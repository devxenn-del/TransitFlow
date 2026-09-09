<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A Company Admin editing their own company's branding / receipt /
 * organization-identity settings — the multi-tenant replacement for BITS'
 * single global `system_settings` row (docs/MIGRATION_MAP.md §K). The
 * company is always the caller's own; image uploads have their own routes.
 */
class UpdateCompanySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->company !== null
            && $user->hasPermissionTo('company.settings.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Branding
            'color_accent' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'color_accent_dark' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],

            // Receipt
            'receipt_width_mm' => ['sometimes', 'numeric', 'between:40,120'],
            'receipt_org_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'ticket_footer' => ['sometimes', 'nullable', 'string', 'max:150'],

            // Organization identity
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'otc_accreditation_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'org_email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'org_contact_number' => ['sometimes', 'nullable', 'string', 'max:50'],

            // Report PDF page setup
            'pdf_paper_size' => ['sometimes', 'string', 'in:a4,letter,legal'],
            'pdf_orientation' => ['sometimes', 'string', 'in:portrait,landscape'],
            'pdf_margin_mm' => ['sometimes', 'integer', 'between:0,40'],
        ];
    }

    /**
     * The string columns are `NOT NULL DEFAULT ''` (BITS parity). A client
     * that clears a field sends `null`; store it as `''`.
     *
     * @return array<string, mixed>
     */
    public function settingsData(): array
    {
        $strings = [
            'receipt_org_name', 'ticket_footer', 'registration_number',
            'otc_accreditation_number', 'org_email', 'org_contact_number',
        ];

        $data = $this->validated();

        foreach ($strings as $field) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                $data[$field] = '';
            }
        }

        return $data;
    }
}
