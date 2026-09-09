<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A Company Admin editing their own company's profile. The company being
 * edited is always the authenticated user's — never taken from the request
 * — so there is no id to validate or authorize against here beyond "is a
 * company admin with an active company".
 */
class UpdateCompanyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->isCompanyAdmin()
            && $user->company !== null
            && $user->can('update', $user->company);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'address_barangay' => ['nullable', 'string', 'max:120'],
            'address_city' => ['nullable', 'string', 'max:120'],
            'address_province' => ['nullable', 'string', 'max:120'],
        ];
    }
}
