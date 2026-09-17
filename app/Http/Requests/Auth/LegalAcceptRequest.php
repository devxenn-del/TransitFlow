<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Any authenticated user may accept the currently-active legal documents on
 * their own behalf — no permission gate. LegalAcceptanceController rejects
 * versions that don't match what's currently active.
 */
class LegalAcceptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Nullable: a document type with nothing published yet has no version
     * to send. LegalAcceptanceController still requires a matching version
     * for whichever type IS currently active.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'privacy_version' => ['nullable', 'integer', 'min:1'],
            'terms_version' => ['nullable', 'integer', 'min:1'],
            'app_version' => ['nullable', 'string', 'max:30'],
            'platform' => ['required', 'string', 'in:android,web'],
        ];
    }
}
