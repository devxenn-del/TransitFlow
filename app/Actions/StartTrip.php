<?php

namespace App\Actions;

use App\Models\Attendance;
use App\Models\CashCount;
use App\Models\Driver;
use App\Models\Route;
use App\Models\Terminal;
use App\Models\Trip;
use App\Models\User;
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
     * @param  array{bus_id:int, driver_id:int, origin:string, coverage_origin:string, coverage_destination:string}  $input
     */
    public function handle(User $conductor, array $input): Trip
    {
        if (! Attendance::query()->forUser($conductor->id)->open()->exists()) {
            throw ValidationException::withMessages(['attendance' => 'Clock in before starting a trip.']);
        }

        if (Trip::query()->where('conductor_id', $conductor->id)->live()->exists()) {
            throw ValidationException::withMessages(['trip' => 'You already have a trip in progress.']);
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

        $terminal = Terminal::query()->where('name', $input['origin'])->where('status', 'Active')->first();
        if ($terminal === null) {
            throw ValidationException::withMessages(['origin' => 'That terminal is not available.']);
        }

        $priced = Route::query()
            ->where('origin', $input['coverage_origin'])
            ->where('destination', $input['coverage_destination'])
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
            'origin' => $input['origin'],
            'coverage_origin' => $input['coverage_origin'],
            'coverage_destination' => $input['coverage_destination'],
            'op_date' => $now->toDateString(),
            'shift' => CashCount::shiftForHour($now->hour),
            'status' => $terminal->skipsTerminalBoarding() ? 'OnTrip' : 'Departure',
            'started_at' => $now,
            'marked_on_trip_at' => $terminal->skipsTerminalBoarding() ? $now : null,
        ]);
    }
}
