<?php

namespace App\Support;

use App\Models\Trip;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Helpers for the conductor app's Offline Mode replays (BITS offline_sync=1).
 *
 * A replay carries the phone's own time for when something really happened
 * (started_at, printed_at, issued_at, dispatched_at, ended_at). It is
 * trusted only within sane bounds: never in the future, never older than
 * {@see self::MAX_AGE_DAYS} days — the app keeps unsynced work that long.
 */
final class OfflineSync
{
    public const MAX_AGE_DAYS = 7;

    /** The phone's time for an event, clamped — null/invalid/out-of-range falls back to now(). */
    public static function time(?string $iso, ?Carbon $notBefore = null): Carbon
    {
        $now = now();
        if ($iso === null || trim($iso) === '') {
            return $now;
        }

        try {
            $at = Carbon::parse($iso)->setTimezone($now->getTimezone());
        } catch (\Throwable) {
            return $now;
        }

        if ($at->greaterThan($now) || $at->lessThan($now->copy()->subDays(self::MAX_AGE_DAYS))) {
            return $now;
        }
        if ($notBefore !== null && $at->lessThan($notBefore)) {
            return $notBefore->copy();
        }

        return $at;
    }

    public static function isReplay(Request $request): bool
    {
        return $request->boolean('offline_sync');
    }

    /**
     * The trip a sale is for: the conductor's live trip — or, for an offline
     * replay naming its trip_id, that trip even if it has ended since (the
     * phone sold on it before it ended; the sale must not be lost).
     */
    public static function tripForSale(Request $request, User $conductor): ?Trip
    {
        if (self::isReplay($request) && $request->filled('trip_id')) {
            return Trip::query()
                ->where('conductor_id', $conductor->id)
                ->whereKey($request->integer('trip_id'))
                ->where(fn ($q) => $q->live()->orWhere(fn ($ended) => $ended
                    ->where('status', 'Arrived')
                    ->where('ended_at', '>=', now()->subDays(self::MAX_AGE_DAYS))))
                ->first();
        }

        return Trip::query()
            ->where('conductor_id', $conductor->id)
            ->live()
            ->first();
    }
}
