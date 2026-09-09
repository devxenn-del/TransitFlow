<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePassengerTypeArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('passenger_type')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $ptId = $this->route('passenger_type')->id;
        $articleId = $this->route('article')->id;

        return [
            'label' => [
                'sometimes', 'string', 'max:100',
                Rule::unique('passenger_type_articles', 'label')->where('passenger_type_id', $ptId)->ignore($articleId),
            ],
            'amount' => ['sometimes', 'numeric', 'min:0', 'max:99999999.99'],
            'sort_order' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in(['Active', 'Inactive'])],
        ];
    }
}
