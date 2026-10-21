<?php

namespace App\Http\Requests\Auth;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * A normal password change asks for the current password. A user still on
 * a temporary password (`must_change_password`) just signed in with it, so
 * they only choose the new one — it must still differ from the temporary.
 */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->user()?->must_change_password) {
            return [
                'password' => ['required', 'confirmed', Password::min(8), $this->differsFromTheTemporaryPassword()],
            ];
        }

        return [
            'current_password' => ['required', 'current_password:sanctum'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)],
        ];
    }

    private function differsFromTheTemporaryPassword(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && Hash::check($value, (string) $this->user()->password)) {
                $fail('The new password must be different from the temporary password.');
            }
        };
    }
}
