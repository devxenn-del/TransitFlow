<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFeeAssignmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('fees.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'applies_to_all_companies' => ['required', 'boolean'],
            'company_ids' => ['present_if:applies_to_all_companies,false', 'array'],
            'company_ids.*' => ['integer', 'distinct', Rule::exists('companies', 'id')->withoutTrashed()],
        ];
    }
}
