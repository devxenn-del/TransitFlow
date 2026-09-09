<?php

namespace App\Http\Requests\Company;

use App\Models\PassengerType;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePassengerTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PassengerType::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();

        return [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('passenger_types', 'name')->where('company_id', $companyId),
            ],
            'fare_mode' => ['required', Rule::in(PassengerType::FARE_MODES)],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sort_order' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in(PassengerType::STATUSES)],
        ];
    }

    protected function prepareForValidation(): void
    {
        // A Manual Amount type never carries a percentage discount.
        if ($this->input('fare_mode') === 'Manual Amount') {
            $this->merge(['discount_percent' => 0]);
        }
    }
}
