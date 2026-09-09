<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StorePassengerTypeArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('passenger_type')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        // Articles only make sense on a Manual Amount type.
        if ($this->route('passenger_type')->fare_mode !== 'Manual Amount') {
            throw ValidationException::withMessages([
                'label' => 'Articles can only be added to a Manual Amount passenger type.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $ptId = $this->route('passenger_type')->id;

        return [
            'label' => [
                'required', 'string', 'max:100',
                Rule::unique('passenger_type_articles', 'label')->where('passenger_type_id', $ptId),
            ],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'sort_order' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in(['Active', 'Inactive'])],
        ];
    }
}
