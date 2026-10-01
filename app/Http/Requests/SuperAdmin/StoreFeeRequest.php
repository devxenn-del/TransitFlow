<?php

namespace App\Http\Requests\SuperAdmin;

use App\Enums\BillingFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('fees.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('fees', 'name')->withoutTrashed()->ignore($this->route('fee'))],
            'description' => ['nullable', 'string', 'max:2000'],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'billing_frequency' => ['required', new Enum(BillingFrequency::class)],
            'billing_interval_months' => ['nullable', 'required_if:billing_frequency,'.BillingFrequency::Custom->value, 'integer', 'min:1', 'max:120'],
            'applies_to_all_companies' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'effective_date' => ['required', 'date'],
        ];
    }

    /**
     * Validated attributes, with the custom interval cleared for every
     * frequency except `custom`.
     *
     * @return array<string, mixed>
     */
    public function feeAttributes(): array
    {
        $attributes = $this->validated();

        if (($attributes['billing_frequency'] ?? null) !== BillingFrequency::Custom->value) {
            $attributes['billing_interval_months'] = null;
        }

        return $attributes;
    }
}
