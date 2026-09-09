<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Super Admin editing any company's profile. (A Company Admin edits their
 * own via App\Http\Requests\Company\UpdateCompanyProfileRequest.)
 */
class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('company')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->route('company')->id;

        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'code' => ['sometimes', 'string', 'max:32', 'alpha_dash', Rule::unique('companies', 'code')->ignore($companyId)],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'address_barangay' => ['nullable', 'string', 'max:120'],
            'address_city' => ['nullable', 'string', 'max:120'],
            'address_province' => ['nullable', 'string', 'max:120'],
            'can_create_accounts' => ['sometimes', 'boolean'],
        ];
    }
}
