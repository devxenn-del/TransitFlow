<?php

namespace App\Http\Requests\Company;

use App\Models\PassengerType;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePassengerTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('passenger_type')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();
        $id = $this->route('passenger_type')->id;

        return [
            'name' => [
                'sometimes', 'string', 'max:100',
                Rule::unique('passenger_types', 'name')->where('company_id', $companyId)->ignore($id),
            ],
            'fare_mode' => ['sometimes', Rule::in(PassengerType::FARE_MODES)],
            'discount_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'sort_order' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in(PassengerType::STATUSES)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $mode = $this->input('fare_mode', $this->route('passenger_type')->fare_mode);
        if ($mode === 'Manual Amount') {
            $this->merge(['discount_percent' => 0]);
        }
    }
}
