<?php

namespace App\Http\Requests\Company;

use App\Models\Franchise;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFranchiseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Franchise::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'applicant_name' => ['required', 'string', 'max:150'],
            'route_description' => ['nullable', 'string', 'max:255'],
            'route_origin' => ['nullable', 'string', 'max:150'],
            'route_destination' => ['nullable', 'string', 'max:150'],
            'case_no' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(Franchise::STATUSES)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'route_description' => '',
            'route_origin' => '',
            'route_destination' => '',
            'case_no' => '',
        ]);
    }
}
