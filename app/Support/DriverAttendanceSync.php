<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\Driver;
use App\Models\DriverAttendance;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Keeps driver clock-ins in step with the conductor's shift. Drivers have no
 * account: the conductor's login verifies the driver (driver code, recorded on
 * the token — Driver::verifiedIdForToken()), so:
 *
 *   conductor clocks in            → that driver is clocked in      (ToggleAttendance)
 *   conductor clocks out / ends    → the driver is clocked out      (ToggleAttendance, ConfirmShiftEnd)
 *   office force-closes the shift  → the driver is clocked out too  (Company\AttendanceController)
 *   conductor signs in again with
 *   another driver while on shift  → old driver out, new one in    (AuthController)
 *
 * Queries skip CompanyScope on purpose: these run during sign-in and from
 * actions, where the company context may not be resolved yet; every row is
 * keyed by the conductor's own ids.
 */
final class DriverAttendanceSync
{
    /**
     * Clock a driver in for this conductor shift. A driver works with one
     * conductor at a time, so any other open period of theirs is closed first.
     */
    public static function open(Attendance $period, int $driverId, string $source = 'app'): DriverAttendance
    {
        $now = now();

        DriverAttendance::query()->withoutGlobalScopes()
            ->where('driver_id', $driverId)
            ->open()
            ->update(['clock_out_at' => $now, 'clock_out_source' => 'auto', 'updated_at' => $now]);

        return DriverAttendance::query()->withoutGlobalScopes()->create([
            'company_id' => $period->company_id,
            'driver_id' => $driverId,
            'conductor_id' => $period->user_id,
            'conductor_attendance_id' => $period->id,
            'clock_in_at' => $now,
            'clock_in_source' => $source,
        ]);
    }

    /** Clock out whichever driver is on this conductor shift. */
    public static function closeFor(
        Attendance $period,
        ?CarbonInterface $at = null,
        string $source = 'app',
        ?User $by = null,
        ?string $note = null,
    ): void {
        $at ??= now();

        DriverAttendance::query()->withoutGlobalScopes()
            ->where('conductor_attendance_id', $period->id)
            ->open()
            ->get()
            ->each(fn (DriverAttendance $row) => $row->update([
                // Never before the driver's own clock-in (a swap mid-shift).
                'clock_out_at' => $at->lessThan($row->clock_in_at) ? $row->clock_in_at : $at,
                'clock_out_source' => $source,
                'closed_by' => $by?->id,
                'closed_note' => $note,
            ]));
    }

    /**
     * The conductor signed in with this driver. If they're on shift and
     * another driver (or none) is clocked in with them, swap to this one.
     */
    public static function switchDriver(User $conductor, Driver $driver, string $source = 'app'): void
    {
        $period = Attendance::query()->withoutGlobalScopes()
            ->where('user_id', $conductor->id)
            ->open()
            ->latest('clock_in_at')
            ->first();
        if ($period === null) {
            return;
        }

        $current = DriverAttendance::query()->withoutGlobalScopes()
            ->where('conductor_attendance_id', $period->id)
            ->open()
            ->first();
        if ($current !== null && $current->driver_id === $driver->id) {
            return;
        }

        self::closeFor($period, now(), 'auto');
        self::open($period, $driver->id, $source);
    }
}
