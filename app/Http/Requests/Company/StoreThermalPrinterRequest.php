<?php

namespace App\Http\Requests\Company;

use App\Models\ThermalPrinter;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreThermalPrinterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('thermalprinters.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();

        return [
            'device_id' => [
                'required', 'string', 'max:40',
                Rule::unique('thermal_printers', 'device_id')->where('company_id', $companyId),
            ],
            'mac_address' => ['nullable', 'string', 'max:20'],
            'model' => ['required', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(ThermalPrinter::STATUSES)],
        ];
    }
}
