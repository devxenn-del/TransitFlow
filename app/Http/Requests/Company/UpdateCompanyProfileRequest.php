<?php

namespace App\Http\Requests\Company;

use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Editing the bound company's profile (App\Support\CompanyContext): a
 * Company Admin's own company, or the one a Super Admin has scoped into. The
 * company is never taken from the request body.
 */
class UpdateCompanyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $company = app(CompanyContext::class)->company();

        // CompanyPolicy::update — a Company Admin of this company, or the
        // Super Admin (Gate::before) scoped into it.
        return $user !== null
            && $company !== null
            && $user->can('update', $company);
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
