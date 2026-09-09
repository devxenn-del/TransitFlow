<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Account lock/unlock — BITS `auth/accountLock.php` (docs/MIGRATION_MAP.md
 * §4, §B). Two independent lock reasons share `users.locked_at/lock_type/
 * locked_by`:
 *  - 'shift_end': set the moment a conductor confirms their end-of-shift
 *    summary (App\Actions\ConfirmShiftEnd). Auto-expires — isLocked() below
 *    treats it as unlocked again once the wall clock passes the next 3:59 AM
 *    after locked_at. Nothing ever clears the row; it's a pure computation
 *    on read.
 *  - 'admin': set/cleared by a company admin (accounts.edit). Persists
 *    until an admin explicitly unlocks it — never auto-expires.
 */
class AccountLock
{
    /**
     * Grace window after a 'shift_end' lock is stamped: the account still
     * has full access for this long so the conductor can Clock Out before
     * losing access until the next 3:59 AM. Measured from locked_at.
     */
    public const SHIFT_END_GRACE_SECONDS = 15 * 60;

    /**
     * @param  bool  $allowShiftEndGrace  True (default) for an
     *                                    already-established session (checked on every request): a
     *                                    'shift_end' lock is not in force for SHIFT_END_GRACE_SECONDS
     *                                    after it is stamped, so the conductor can still Clock Out. Pass
     *                                    false from a fresh sign-in (login / pin-login) so the lock bites
     *                                    immediately there — the grace is only meant to let the in-flight
     *                                    session wrap up, not to let anyone start a new one.
     */
    public static function isLocked(User $user, bool $allowShiftEndGrace = true): bool
    {
        if ($user->locked_at === null) {
            return false;
        }

        if ($user->lock_type === 'admin') {
            return true;
        }

        if ($allowShiftEndGrace && now()->lessThan($user->locked_at->copy()->addSeconds(self::SHIFT_END_GRACE_SECONDS))) {
            return false;
        }

        return now()->lessThan(self::nextShiftUnlockTimestamp($user->locked_at));
    }

    /** The next 3:59 AM strictly after $lockedAt — same calendar day if locked before 3:59 AM, the next day otherwise. */
    public static function nextShiftUnlockTimestamp(CarbonInterface $lockedAt): CarbonInterface
    {
        $sameDayUnlock = $lockedAt->copy()->setTimeFromTimeString('03:59:00');

        return $sameDayUnlock->greaterThan($lockedAt) ? $sameDayUnlock : $sameDayUnlock->addDay();
    }

    /** Human-readable reason + (for a shift lock) the exact unlock time. */
    public static function reasonMessage(User $user): string
    {
        if ($user->lock_type === 'admin') {
            return 'Your account has been locked by an administrator. Contact your administrator to unlock it.';
        }

        return 'Your account is locked until '.self::nextShiftUnlockTimestamp($user->locked_at)->format('g:i A').' after printing your shift summary.';
    }

    public static function lockShiftEnd(User $user): void
    {
        $user->forceFill(['locked_at' => now(), 'lock_type' => 'shift_end', 'locked_by' => null])->save();
    }

    /**
     * Ends the shift-end grace window right now. Called when the conductor
     * confirms shift end (which also Clocks them out) — the grace only
     * exists to let that happen, so once it's done the account has no
     * reason to stay open. No-op if there is no shift_end lock, or its
     * grace has already elapsed.
     */
    public static function endShiftEndGraceNow(User $user): void
    {
        if ($user->lock_type !== 'shift_end' || $user->locked_at === null) {
            return;
        }

        $graceEnd = $user->locked_at->copy()->addSeconds(self::SHIFT_END_GRACE_SECONDS);

        if (now()->lessThan($graceEnd)) {
            $user->forceFill(['locked_at' => now()->subSeconds(self::SHIFT_END_GRACE_SECONDS)])->save();
        }
    }

    public static function lockAdmin(User $user, User $admin): void
    {
        $user->forceFill(['locked_at' => now(), 'lock_type' => 'admin', 'locked_by' => $admin->id])->save();
    }

    public static function unlock(User $user): void
    {
        $user->forceFill(['locked_at' => null, 'lock_type' => null, 'locked_by' => null])->save();
    }
}
