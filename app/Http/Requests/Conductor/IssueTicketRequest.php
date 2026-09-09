<?php

namespace App\Http\Requests\Conductor;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('tickets.issue') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'passenger_type_id' => ['required', 'integer'],
            'route_id' => ['nullable', 'integer'],
            'manual_amount' => ['nullable', 'numeric', 'min:0'],
            'article_label' => ['nullable', 'string', 'max:100'],
            'boarding_type' => ['required', Rule::in(Ticket::BOARDING_TYPES)],
            'payment_method' => ['required', Rule::in(Ticket::PAYMENT_METHODS)],
            'qr_reference' => ['nullable', 'string', 'max:6'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'client_uuid' => ['nullable', 'string', 'max:64'],
        ];
    }
}
