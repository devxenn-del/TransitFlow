<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class SaveRouteStopsRequest extends FormRequest
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
            'stops' => ['present', 'array', 'max:60'],
            'stops.*' => ['string', 'max:150'],
            // When true, a removed stop's fares are cleared instead of blocking.
            'force' => ['sometimes', 'boolean'],
        ];
    }
}
