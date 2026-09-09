<?php

namespace App\Http\Requests\Company;

use App\Models\OpExpense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOpExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('expenses.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'bus_id' => ['required', 'integer'],
            'op_date' => ['required', 'date', 'before_or_equal:today'],
            'shift' => ['required', Rule::in(['Morning', 'Evening'])],
            'category' => ['required', Rule::in(OpExpense::CATEGORIES)],
            'description' => ['required', 'string', 'max:255'],
        ];

        foreach (['q1000', 'q500', 'q200', 'q100', 'q50', 'q20', 'q10', 'q5', 'q1'] as $field) {
            $rules[$field] = ['sometimes', 'integer', 'min:0', 'max:100000'];
        }

        return $rules;
    }
}
