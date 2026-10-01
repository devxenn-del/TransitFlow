<?php

namespace App\Http\Requests\Company;

use App\Models\Bus;
use App\Models\Role;
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
            // The bus's regular driver, and the conductor accounts allowed to run it.
            'driver_id' => ['sometimes', 'nullable', 'integer', Rule::exists('drivers', 'id')->where('company_id', $companyId)],
            'conductor_ids' => ['sometimes', 'array'],
            'conductor_ids.*' => [
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->whereIn('role_id', Role::query()->forCompany($companyId)->where('key', 'conductor')->select('id'))),
            ],
        ];
    }
}
