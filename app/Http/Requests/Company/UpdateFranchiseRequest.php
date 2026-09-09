<?php

namespace App\Http\Requests\Company;

use App\Models\Franchise;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFranchiseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('franchise')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'applicant_name' => ['sometimes', 'string', 'max:150'],
            'route_description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'route_origin' => ['sometimes', 'nullable', 'string', 'max:150'],
            'route_destination' => ['sometimes', 'nullable', 'string', 'max:150'],
            'case_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(Franchise::STATUSES)],
        ];
    }
}
