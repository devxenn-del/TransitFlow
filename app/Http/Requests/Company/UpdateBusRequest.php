<?php

namespace App\Http\Requests\Company;

use App\Models\Bus;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('bus')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();
        $busId = $this->route('bus')->id;

        return [
            'bus_number' => [
                'sometimes', 'string', 'max:50',
                Rule::unique('buses', 'bus_number')->where('company_id', $companyId)->ignore($busId),
            ],
            'plate_number' => [
                'sometimes', 'string', 'max:50',
                Rule::unique('buses', 'plate_number')->where('company_id', $companyId)->ignore($busId),
            ],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'model' => ['nullable', 'string', 'max:100'],
            'vehicle_type' => ['sometimes', Rule::in(Bus::VEHICLE_TYPES)],
            'status' => ['sometimes', Rule::in(['Active', 'Inactive', 'Maintenance'])],
        ];
    }
}
