<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class EndChargingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('charging.record') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'battery_end_pct' => ['required', 'integer', 'between:0,100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
