<?php

namespace App\Http\Requests\Company;

use App\Models\FuelRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFuelRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('fuel.record') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bus_id' => ['required', 'integer'],
            'fuel_type' => ['required', Rule::in(FuelRecord::FUEL_TYPES)],
            'liters' => ['required', 'numeric', 'gt:0', 'max:99999.99'],
            'price_per_liter' => ['required', 'numeric', 'gt:0', 'max:9999.99'],
            'odometer' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'station' => ['sometimes', 'nullable', 'string', 'max:120'],
            'fueled_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:now'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
