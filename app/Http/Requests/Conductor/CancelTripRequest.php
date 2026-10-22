<?php

namespace App\Http\Requests\Conductor;

use Illuminate\Foundation\Http\FormRequest;

class CancelTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('trips.cancel') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
            'cancel_stop' => ['nullable', 'string', 'max:255'],
            'refund_ticket_ids' => ['nullable', 'array'],
            'refund_ticket_ids.*' => ['integer'],
        ];
    }
}
