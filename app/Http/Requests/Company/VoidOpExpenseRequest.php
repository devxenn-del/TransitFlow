<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Only a manager (holder of `expenses.void`) may reverse an expense.
 */
class VoidOpExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('expenses.void') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
            'pin' => ['sometimes', 'nullable', 'string', 'max:12'],
        ];
    }
}
