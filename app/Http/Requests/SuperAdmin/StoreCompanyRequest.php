<?php

namespace App\Http\Requests\SuperAdmin;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Company::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:32', 'alpha_dash', Rule::unique('companies', 'code')],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'address_barangay' => ['nullable', 'string', 'max:120'],
            'address_city' => ['nullable', 'string', 'max:120'],
            'address_province' => ['nullable', 'string', 'max:120'],

            'can_create_accounts' => ['sometimes', 'boolean'],

            'admin' => ['nullable', 'array'],
            'admin.name' => ['required_with:admin', 'string', 'max:150'],
            'admin.email' => ['required_with:admin', 'email', 'max:150', Rule::unique('users', 'email')],
            'admin.password' => ['required_with:admin', 'string', 'min:8'],
        ];
    }
}
