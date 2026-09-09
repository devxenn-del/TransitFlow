<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Self-service: set or replace your own App PIN. The current password
 * confirms identity, same as VoidPin's SetVoidPinRequest.
 */
class SetPinRequest extends FormRequest
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
        return [
            'current_password' => ['required', 'current_password'],
            'pin' => ['required', 'string', 'regex:/^\d{4}$/', 'confirmed'],
        ];
    }
}
