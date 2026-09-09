<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveRemittanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('remittances.receive') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [];
        foreach (['q1000', 'q500', 'q200', 'q100', 'q50', 'q20', 'q10', 'q5', 'q1'] as $field) {
            $rules[$field] = ['sometimes', 'integer', 'min:0', 'max:100000'];
        }

        return $rules;
    }
}
