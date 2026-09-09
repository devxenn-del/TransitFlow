<?php

namespace App\Http\Requests\Conductor;

use Illuminate\Foundation\Http\FormRequest;

class EndTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('trips.end') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Optional — defaults to the full amount collected.
            'remitted_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999.99'],
        ];
    }
}
