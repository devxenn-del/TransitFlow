<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirmation gate for a Clean Data run (docs/MIGRATION_MAP.md §K). The
 * caller must type their own company's `code` into `confirm` — a deliberate
 * speed bump on a destructive, irreversible action.
 */
class CleanCompanyDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->company !== null
            && $user->hasPermissionTo('cleandata.run');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'string'],
        ];
    }

    protected function passedValidation(): void
    {
        $expected = (string) $this->user()->company->code;

        if (! hash_equals($expected, (string) $this->input('confirm'))) {
            abort(422, 'Type your company code exactly to confirm.');
        }
    }
}
