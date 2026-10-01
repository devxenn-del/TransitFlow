<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyFeeRateRequest extends FormRequest
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
            'fee_id' => ['required', 'integer', Rule::exists('fees', 'id')->withoutTrashed()],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'is_active' => ['sometimes', 'boolean'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
