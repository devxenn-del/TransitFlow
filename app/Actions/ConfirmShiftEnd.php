<?php

namespace App\Actions;

use App\Models\Attendance;
use App\Models\Trip;
use App\Models\User;
use App\Support\AccountLock;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * A conductor confirms their end-of-shift summary — BITS
 * `api/trips/confirmShiftEnd.php`. Re-verifies the PIN, then locks the
 * account ('shift_end', auto-expiring at the next 3:59 AM) AND Clocks them
 * out: printing the summary IS ending the whole day's operation, not a
 * separate step to remember afterward.
 */
class ConfirmShiftEnd
{
    public function handle(User $conductor, string $pin): void
    {
        if (! $conductor->hasPin() || ! Hash::check($pin, $conductor->pin_hash)) {
            throw ValidationException::withMessages(['pin' => 'Incorrect PIN.']);
        }

        if (Trip::query()->where('conductor_id', $conductor->id)->whereIn('status', Trip::LIVE)->exists()) {
            throw ValidationException::withMessages([
                'trip' => 'You have an active trip. End your trip before confirming shift end.',
            ]);
        }

        AccountLock::lockShiftEnd($conductor);
        AccountLock::endShiftEndGraceNow($conductor);

        $open = Attendance::query()->forUser($conductor->id)->open()->lockForUpdate()->first();

        if ($open !== null) {
            $open->update(['clock_out_at' => now(), 'clock_out_source' => 'app']);
        }
    }
}
