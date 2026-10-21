<?php

namespace App\Actions;

use App\Models\Attendance;
use App\Models\Driver;
use App\Models\User;
use App\Support\DriverAttendanceSync;
use Illuminate\Support\Facades\DB;

/**
 * Clock a conductor in or out — BITS `api/attendance/toggle.php`.
 *
 * If the conductor has an open period it is closed; otherwise a new period
 * is opened. A row lock on the user's open periods serialises concurrent
 * toggles so a conductor can never hold two open periods at once.
 *
 * The driver verified at this conductor's sign-in is clocked in and out with
 * them (App\Support\DriverAttendanceSync) — drivers have no account of their own.
 */
class ToggleAttendance
{
    public function handle(User $conductor, string $source = 'web'): Attendance
    {
        return DB::transaction(function () use ($conductor, $source): Attendance {
            $open = Attendance::query()
                ->forUser($conductor->id)
                ->open()
                ->lockForUpdate()
                ->first();

            if ($open !== null) {
                $now = now();
                $open->update([
                    'clock_out_at' => $now,
                    'clock_out_source' => $source,
                ]);
                DriverAttendanceSync::closeFor($open, $now, $source);

                return $open->refresh();
            }

            $period = Attendance::query()->create([
                'company_id' => $conductor->company_id,
                'user_id' => $conductor->id,
                'clock_in_at' => now(),
                'clock_in_source' => $source,
            ]);

            $driverId = Driver::verifiedIdForToken($conductor);
            if ($driverId !== null) {
                DriverAttendanceSync::open($period, $driverId, $source);
            }

            return $period;
        });
    }
}
