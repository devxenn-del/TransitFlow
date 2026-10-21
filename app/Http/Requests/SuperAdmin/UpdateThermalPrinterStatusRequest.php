<?php

namespace App\Http\Requests\SuperAdmin;

use App\Enums\ThermalPrinterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/** Move a printer to another inventory status — transitions checked by ChangeThermalPrinterStatus. */
class UpdateThermalPrinterStatusRequest extends FormRequest
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
            'status' => ['required', new Enum(ThermalPrinterStatus::class)],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function status(): ThermalPrinterStatus
    {
        return ThermalPrinterStatus::from($this->validated('status'));
    }
}
