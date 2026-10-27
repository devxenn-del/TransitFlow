<?php

namespace App\Http\Requests\Conductor;

use Illuminate\Foundation\Http\FormRequest;

class IssueDispatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('dispatch.issue') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'barker_name' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
            // Idempotency — a replayed dispatch is never recorded twice.
            'client_uuid' => ['sometimes', 'nullable', 'string', 'max:64'],
            // Offline Mode replay (App\Support\OfflineSync).
            'offline_sync' => ['sometimes', 'nullable', 'boolean'],
            'trip_id' => ['sometimes', 'nullable', 'integer'],
            'dispatched_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
