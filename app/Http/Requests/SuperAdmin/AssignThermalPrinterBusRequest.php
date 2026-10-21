<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Put a printer on a bus, move it, or (null `bus_id`) take it off. Whether
 * the bus belongs to the printer's company is checked by
 * AssignThermalPrinterBus against the database, not here.
 */
class AssignThermalPrinterBusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('printers.inventory.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bus_id' => ['present', 'nullable', 'integer'],
            'company_id' => ['prohibited'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
