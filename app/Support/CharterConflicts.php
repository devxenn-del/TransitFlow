<?php

namespace App\Support;

use App\Models\CharterBooking;
use App\Models\Trip;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Prevents double-booking a bus, driver or conductor for a charter window
 * [departure_at, return_at]. Taken means:
 *   - held by another charter (Pending / Approved / InProgress) whose window
 *     overlaps, or
 *   - when the window covers right now: out on a live trip.
 * Regular trips aren't scheduled ahead, so only a live one can clash; the
 * reverse (a Regular trip starting during an approved charter) is refused in
 * App\Actions\StartTrip via {@see self::activeCharterFor()}.
 * Runs inside the current company (CompanyScope on both models).
 */
final class CharterConflicts
{
    /**
     * Everything taken during the window, keyed by crew kind then id, with a
     * human reason — what the booking form greys out.
     *
     * @return array{bus: array<int, string>, driver: array<int, string>, conductor: array<int, string>}
     */
    public static function busy(CarbonInterface $from, CarbonInterface $to, ?int $exceptBookingId = null): array
    {
        $busy = ['bus' => [], 'driver' => [], 'conductor' => []];

        $bookings = CharterBooking::query()
            ->holding()
            ->overlapping($from, $to)
            ->when($exceptBookingId !== null, fn ($q) => $q->whereKeyNot($exceptBookingId))
            ->get(['id', 'reference', 'client_name', 'departure_at', 'return_at', 'bus_id', 'driver_id', 'conductor_id', 'trip_id']);

        foreach ($bookings as $b) {
            $why = sprintf('Booked for %s (%s, %s – %s)', $b->reference, $b->client_name,
                $b->departure_at->format('M d h:iA'), $b->return_at->format('M d h:iA'));
            $busy['bus'][$b->bus_id] ??= $why;
            $busy['driver'][$b->driver_id] ??= $why;
            $busy['conductor'][$b->conductor_id] ??= $why;
        }

        if ($from->lessThanOrEqualTo(now()) && $to->greaterThan(now())) {
            $ownTripId = $exceptBookingId !== null
                ? CharterBooking::query()->whereKey($exceptBookingId)->value('trip_id')
                : null;
            $trips = Trip::query()->live()
                ->when($ownTripId !== null, fn ($q) => $q->whereKeyNot($ownTripId))
                ->get(['id', 'reference', 'bus_id', 'driver_id', 'conductor_id']);
            foreach ($trips as $t) {
                $why = 'Out on trip '.$t->reference.' right now';
                $busy['bus'][$t->bus_id] ??= $why;
                $busy['driver'][$t->driver_id] ??= $why;
                $busy['conductor'][$t->conductor_id] ??= $why;
            }
        }

        return $busy;
    }

    /**
     * Throws a 422 naming each taken crew member (bus_id / driver_id / conductor_id).
     *
     * @throws ValidationException
     */
    public static function assertFree(
        CarbonInterface $from,
        CarbonInterface $to,
        int $busId,
        int $driverId,
        int $conductorId,
        ?int $exceptBookingId = null,
    ): void {
        $busy = self::busy($from, $to, $exceptBookingId);
        $errors = [];

        if (isset($busy['bus'][$busId])) {
            $errors['bus_id'] = 'That bus is not available: '.$busy['bus'][$busId].'.';
        }
        if (isset($busy['driver'][$driverId])) {
            $errors['driver_id'] = 'That driver is not available: '.$busy['driver'][$driverId].'.';
        }
        if (isset($busy['conductor'][$conductorId])) {
            $errors['conductor_id'] = 'That conductor is not available: '.$busy['conductor'][$conductorId].'.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * An Approved charter whose window covers now and that uses this bus,
     * driver or conductor — a Regular trip may not start on it.
     */
    public static function activeCharterFor(int $busId, int $driverId, int $conductorId): ?CharterBooking
    {
        return CharterBooking::query()
            ->where('status', 'Approved')
            ->where('departure_at', '<=', now())
            ->where('return_at', '>', now())
            ->where(fn ($q) => $q->where('bus_id', $busId)
                ->orWhere('driver_id', $driverId)
                ->orWhere('conductor_id', $conductorId))
            ->orderBy('departure_at')
            ->first();
    }
}
