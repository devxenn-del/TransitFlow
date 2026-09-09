<?php

namespace App\Actions;

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Clock a conductor in or out — BITS `api/attendance/toggle.php`.
 *
 * If the conductor has an open period it is closed; otherwise a new period
 * is opened. A row lock on the user's open periods serialises concurrent
 * toggles so a conductor can never hold two open periods at once.
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
                $open->update([
                    'clock_out_at' => now(),
                    'clock_out_source' => $source,
                ]);

                return $open->refresh();
            }

            return Attendance::query()->create([
                'company_id' => $conductor->company_id,
                'user_id' => $conductor->id,
                'clock_in_at' => now(),
                'clock_in_source' => $source,
            ]);
        });
    }
}
