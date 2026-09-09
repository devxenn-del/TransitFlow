<?php

namespace App\Http\Requests\Company;

use App\Models\Bus;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Bus::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();

        return [
            'bus_number' => [
                'required', 'string', 'max:50',
                Rule::unique('buses', 'bus_number')->where('company_id', $companyId),
            ],
            'plate_number' => [
                'required', 'string', 'max:50',
                Rule::unique('buses', 'plate_number')->where('company_id', $companyId),
            ],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'model' => ['nullable', 'string', 'max:100'],
            'vehicle_type' => ['sometimes', Rule::in(Bus::VEHICLE_TYPES)],
            'status' => ['sometimes', Rule::in(['Active', 'Inactive', 'Maintenance'])],
        ];
    }
}
