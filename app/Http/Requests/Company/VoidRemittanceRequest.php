<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Only a manager (holder of `remittances.void`) may reverse a received
 * remittance count.
 */
class VoidRemittanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('remittances.void') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
            'pin' => ['sometimes', 'nullable', 'string', 'max:12'],
        ];
    }
}
