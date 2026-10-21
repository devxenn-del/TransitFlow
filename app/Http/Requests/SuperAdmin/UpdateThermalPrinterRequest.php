<?php

namespace App\Http\Requests\SuperAdmin;

use App\Models\ThermalPrinter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Edit a printer's descriptive details. Its IDs never change, and company /
 * bus / status each have their own validated endpoint, so none of those can
 * be set here.
 */
class UpdateThermalPrinterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('printers.inventory.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('mac_address')) {
            $this->merge(['mac_address' => ThermalPrinter::normalizeMac($this->input('mac_address'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'thermal_id' => ['prohibited'],
            'device_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'bus_id' => ['prohibited'],
            'status' => ['prohibited'],
            'model' => ['sometimes', 'required', 'string', 'max:100'],
            'mac_address' => [
                'sometimes', 'nullable', 'string', 'regex:'.ThermalPrinter::MAC_ADDRESS_PATTERN,
                Rule::unique('thermal_printers', 'mac_address')->ignore($this->printer()->id),
            ],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:40'],
            'date_issued' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->filled('date_issued') && $this->printer()->company_id === null) {
                    $validator->errors()->add('date_issued', 'Only a printer issued to a company has an issue date.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            '*.prohibited' => 'The :attribute cannot be changed here.',
            'mac_address.regex' => 'Enter the MAC address as six pairs of hex digits, e.g. 00:1A:2B:3C:4D:5E.',
            'mac_address.unique' => 'A printer with this MAC address is already registered.',
        ];
    }

    public function printer(): ThermalPrinter
    {
        return $this->route('inventoryPrinter');
    }
}
