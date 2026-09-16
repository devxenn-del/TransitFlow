<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            // Requiredness depends on the account's role, which isn't known
            // until the credentials are checked — enforced in
            // AuthController::login(), not here. See §7/§18 of the Driver
            // Code spec: this must still fail closed server-side even if a
            // client never sends the field at all.
            'driver_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'device_name' => ['sometimes', 'string', 'max:120'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    public function deviceName(): string
    {
        return $this->string('device_name')->value() ?: 'web';
    }

    /**
     * Throttle: 5 failed attempts per email+IP per minute (BITS had no
     * throttle; this is a straight improvement).
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => RateLimiter::availableIn($this->throttleKey()),
            ]),
        ])->status(429);
    }

    public function hitRateLimiter(): void
    {
        RateLimiter::hit($this->throttleKey());
    }

    public function clearRateLimiter(): void
    {
        RateLimiter::clear($this->throttleKey());
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
