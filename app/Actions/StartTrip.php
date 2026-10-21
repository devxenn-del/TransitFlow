<?php

namespace App\Actions;

use App\Models\Attendance;
use App\Models\CharterBooking;
use App\Models\Driver;
use App\Models\Franchise;
use App\Models\Route;
use App\Models\Terminal;
use App\Models\Trip;
use App\Models\User;
use App\Support\CharterConflicts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Starts a conductor's trip, applying BITS' start rules
 * (docs/MIGRATION_MAP.md §4.2 — api/trips/start.php):
 *
 *  - the conductor is clocked in (an open `conductor_attendance` period)
 *  - the conductor has no live trip already
 *  - the bus is one of the conductor's assigned, Active buses
 *  - that bus is not already out on a live trip
 *  - the driver is Active
 *  - the origin is an Active terminal
 *  - the declared coverage route is Active AND priced
 *
 * A Pickup-only origin terminal has no terminal boarding phase, so the
 * trip is created already `OnTrip` instead of `Departure`.
 */
class StartTrip
{
    /**
     * @param  array{bus_id:int, franchise_id?:int|null, driver_id:int, origin:string, coverage_origin:string, coverage_destination:string, trip_type?:string, at_terminal?:bool}  $input
     */
    public function handle(User $conductor, array $input): Trip
    {
        if (! Attendance::query()->forUser($conductor->id)->open()->exists()) {
            throw ValidationException::withMessages(['attendance' => 'Clock in before starting a trip.']);
        }

        if (Trip::query()->where('conductor_id', $conductor->id)->live()->exists()) {
            throw ValidationException::withMessages(['trip' => 'You already have a trip in progress.']);
        }

        if (($input['trip_type'] ?? 'Regular') === 'Special') {
            return $this->startCharter($conductor, (int) $input['charter_booking_id'], (int) $input['driver_id']);
        }

        $bus = $conductor->buses()->where('buses.id', $input['bus_id'])->where('status', 'Active')->first();
        if ($bus === null) {
            throw ValidationException::withMessages(['bus_id' => 'Pick one of your assigned, active buses.']);
        }

        if (Trip::query()->where('bus_id', $bus->id)->live()->exists()) {
            throw ValidationException::withMessages(['bus_id' => 'That bus is already out on a trip.']);
        }

        $driver = Driver::query()->where('id', $input['driver_id'])->where('status', 'Active')->first();
        if ($driver === null) {
            throw ValidationException::withMessages(['driver_id' => 'Pick an active driver.']);
        }

        // A conductor isn't fixed to one driver, but they DO verify a
        // specific driver's code at sign-in (AuthController::verifyDriverCode()) —
        // every trip on that same session must use that same driver. Skipped
        // for a token issued before this feature existed (no such ability
        // recorded yet); it takes effect on that conductor's next sign-in.
        $lockedDriverId = Driver::verifiedIdForToken($conductor);
        if ($lockedDriverId !== null && $lockedDriverId !== $driver->id) {
            throw ValidationException::withMessages(['driver_id' => 'This trip must use the driver you verified at sign-in.']);
        }

        // A Regular trip can't take a bus / driver / conductor that's booked
        // for a charter under way right now (App\Support\CharterConflicts).
        $charter = CharterConflicts::activeCharterFor($bus->id, $driver->id, $conductor->id);
        if ($charter !== null) {
            $field = $charter->bus_id === $bus->id ? 'bus_id' : ($charter->driver_id === $driver->id ? 'driver_id' : 'trip');
            $who = $charter->bus_id === $bus->id ? 'This bus' : ($charter->driver_id === $driver->id ? 'This driver' : 'You');
            throw ValidationException::withMessages([$field => sprintf('%s %s booked for charter %s until %s — start it as a Special trip instead.',
                $who, $who === 'You' ? 'are' : 'is', $charter->reference, $charter->return_at->format('h:i A'))]);
        }

        // No settings row yet for this company (lazily created elsewhere) ->
        // default to the column's own default (terminals in use), same as
        // every company before this flag existed.
        $usesTerminals = $conductor->company?->settings?->uses_terminals ?? true;

        if ($usesTerminals) {
            $terminal = Terminal::query()->where('name', $input['origin'])->where('status', 'Active')->first();
            if ($terminal === null) {
                throw ValidationException::withMessages(['origin' => 'That terminal is not available.']);
            }
        } else {
            // This company has no physical terminals — the conductor picks a
            // route origin directly (Android sends origin = coverage_origin).
            // Auto-provision a Pickup-mode terminal per route origin so
            // Trip.origin keeps pointing at a real, Active terminal without
            // making these companies manage terminal records at all.
            $terminal = Terminal::query()->firstOrCreate(
                ['company_id' => $conductor->company_id, 'name' => $input['origin']],
                ['default_route_origin' => $input['origin'], 'boarding_mode' => 'Pickup', 'status' => 'Active'],
            );
        }

        // The route (franchise) picked at Start Trip, when the app sends it —
        // ticketing then uses that franchise's own stops and fare matrix.
        $franchiseId = null;
        if (! empty($input['franchise_id'])) {
            $franchiseId = Franchise::query()->where('id', $input['franchise_id'])->where('status', 'Active')->value('id');
            if ($franchiseId === null) {
                throw ValidationException::withMessages(['franchise_id' => 'Pick an active route.']);
            }
        }

        $priced = Route::query()
            ->where('origin', $input['coverage_origin'])
            ->where('destination', $input['coverage_destination'])
            ->when($franchiseId !== null, fn ($q) => $q->where('franchise_id', $franchiseId))
            ->priced()
            ->exists();
        if (! $priced) {
            throw ValidationException::withMessages([
                'coverage_destination' => 'That route is not priced yet. Ask an admin to set its fare.',
            ]);
        }

        $now = now();

        return Trip::query()->create([
            'conductor_id' => $conductor->id,
            'bus_id' => $bus->id,
            'bus_number' => $bus->bus_number,
            'driver_id' => $driver->id,
            'franchise_id' => $franchiseId,
            'origin' => $input['origin'],
            'coverage_origin' => $input['coverage_origin'],
            'coverage_destination' => $input['coverage_destination'],
            'trip_type' => $input['trip_type'] ?? 'Regular',
            'at_terminal' => $input['at_terminal'] ?? true,
            'op_date' => $now->toDateString(),
            'shift' => Trip::shiftForHour($now->hour),
            'status' => $terminal->skipsTerminalBoarding() ? 'OnTrip' : 'Departure',
            'started_at' => $now,
            'marked_on_trip_at' => $terminal->skipsTerminalBoarding() ? $now : null,
        ]);
    }

    /** How early before its departure time a charter can be started. */
    private const CHARTER_EARLY_START_HOURS = 2;

    /**
     * A Special trip (charter / hire) runs an Approved charter booking
     * (App\Models\CharterBooking) assigned to this conductor: its bus, its
     * driver — who must be the one verified at sign-in — its pickup point and
     * destination. No route, fares or passenger tickets (App\Actions\IssueTicket
     * refuses them). It starts at `Departure` (waiting at the pickup point);
     * the conductor records leaving with mark-on-trip and arriving with End
     * Trip, and the booking follows (InProgress → Completed — see Trip::booted()).
     */
    private function startCharter(User $conductor, int $bookingId, int $driverId): Trip
    {
        return DB::transaction(function () use ($conductor, $bookingId, $driverId): Trip {
            $booking = CharterBooking::query()->whereKey($bookingId)->lockForUpdate()->first();

            if ($booking === null || $booking->conductor_id !== $conductor->id) {
                throw ValidationException::withMessages(['charter_booking_id' => 'That charter booking is not assigned to you.']);
            }
            if ($booking->status !== 'Approved') {
                throw ValidationException::withMessages(['charter_booking_id' => $booking->status === 'InProgress'
                    ? 'That charter has already been started.'
                    : 'That charter booking is not approved yet.']);
            }
            if (now()->lessThan($booking->departure_at->copy()->subHours(self::CHARTER_EARLY_START_HOURS))) {
                throw ValidationException::withMessages(['charter_booking_id' => sprintf(
                    'Charter %s departs %s — it can be started from %d hours before.',
                    $booking->reference, $booking->departure_at->format('M d, h:i A'), self::CHARTER_EARLY_START_HOURS)]);
            }

            $bus = $booking->bus()->where('status', 'Active')->first();
            if ($bus === null) {
                throw ValidationException::withMessages(['bus_id' => 'The bus booked for this charter is not active. Ask the office to change it.']);
            }
            if (Trip::query()->where('bus_id', $bus->id)->live()->exists()) {
                throw ValidationException::withMessages(['bus_id' => "Bus {$bus->bus_number} is already out on a trip."]);
            }

            $driver = $booking->driver()->where('status', 'Active')->first();
            if ($driver === null) {
                throw ValidationException::withMessages(['driver_id' => 'The driver booked for this charter is not active. Ask the office to change it.']);
            }
            // Every trip on this session uses the driver verified at sign-in.
            $lockedDriverId = Driver::verifiedIdForToken($conductor) ?? $driverId;
            if ($lockedDriverId !== $driver->id) {
                throw ValidationException::withMessages(['driver_id' => "This charter is assigned to driver {$driver->name} — sign out and sign in with that driver's code."]);
            }

            $now = now();
            $trip = Trip::query()->create([
                'conductor_id' => $conductor->id,
                'bus_id' => $bus->id,
                'bus_number' => $bus->bus_number,
                'driver_id' => $driver->id,
                'franchise_id' => null,
                'charter_booking_id' => $booking->id,
                // Shown as "pickup → destination" on the live board / trip monitor.
                'origin' => null,
                'coverage_origin' => Str::limit($booking->pickup_point, 150, ''),
                'coverage_destination' => Str::limit($booking->destination, 150, ''),
                'trip_type' => 'Special',
                'at_terminal' => false,
                'op_date' => $now->toDateString(),
                'shift' => Trip::shiftForHour($now->hour),
                'status' => 'Departure',
                'started_at' => $now,
            ]);

            $booking->update(['status' => 'InProgress', 'trip_id' => $trip->id]);

            return $trip;
        });
    }
}
