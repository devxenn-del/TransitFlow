<?php

namespace App\Http\Requests\Company;

use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignConductorBusesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Managing a conductor's fleet assignment is an account-edit action.
        return $this->user()?->can('update', $this->route('user')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();

        return [
            'bus_ids' => ['present', 'array'],
            'bus_ids.*' => ['integer', Rule::exists('buses', 'id')->where('company_id', $companyId)],
        ];
    }
}
