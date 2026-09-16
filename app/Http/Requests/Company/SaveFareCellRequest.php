<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * One fare-matrix cell save. `amount: null` clears the fare. `touch_discount`
 * true means the `discounted_amount` field in this payload should be written
 * (including clearing it to null); when false the override is left as-is.
 */
class SaveFareCellRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('franchise')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'origin' => ['required', 'string', 'max:150'],
            // Same-stop routes are valid (a loop route back to its own
            // terminal, a flat local fare) — not forced different from origin.
            'destination' => ['required', 'string', 'max:150'],
            'amount' => ['present', 'nullable', 'numeric', 'min:0', 'max:99999.99'],
            'discounted_amount' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'touch_discount' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $amount = $this->input('amount');
            $discounted = $this->input('discounted_amount');

            if ($amount !== null && $discounted !== null && (float) $discounted > (float) $amount) {
                $v->errors()->add('discounted_amount', 'The discounted fare cannot exceed the base fare.');
            }
        });
    }
}
