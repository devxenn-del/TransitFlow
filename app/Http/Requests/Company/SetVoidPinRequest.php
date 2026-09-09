<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A manager sets or replaces their OWN void PIN. The current password
 * confirms identity; the new PIN is 4–8 digits.
 */
class SetVoidPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('voidpin.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password'],
            'pin' => ['required', 'string', 'regex:/^\d{4,8}$/', 'confirmed'],
        ];
    }
}
