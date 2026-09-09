<?php

namespace App\Http\Requests\Company;

use App\Models\ThermalPrinter;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateThermalPrinterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('thermalprinters.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();
        $printerId = $this->route('printer')->id;

        return [
            'device_id' => [
                'sometimes', 'string', 'max:40',
                Rule::unique('thermal_printers', 'device_id')->where('company_id', $companyId)->ignore($printerId),
            ],
            'mac_address' => ['nullable', 'string', 'max:20'],
            'model' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(ThermalPrinter::STATUSES)],
        ];
    }
}
