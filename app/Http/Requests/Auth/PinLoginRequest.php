<?php

namespace App\Http\Requests\Auth;

/**
 * Conductor sign-in by App PIN instead of password — BITS
 * `api/auth/pinLogin.php`. Bus/driver selection happens at Start Trip
 * (App\Actions\StartTrip), not at login, so this only re-implements the
 * credential check, not BITS' bus→driver→conductor wizard.
 */
class PinLoginRequest extends LoginRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'pin' => ['required', 'string', 'regex:/^\d{4}$/'],
            'device_name' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
