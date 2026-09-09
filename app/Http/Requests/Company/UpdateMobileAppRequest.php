<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A Company Admin editing their own company's mobile-app distribution
 * settings — BITS `admin/mobileapp.php` (docs/MIGRATION_MAP.md §K).
 */
class UpdateMobileAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->company !== null
            && $user->hasPermissionTo('mobileapp.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'api_base_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'latest_version' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\d+(\.\d+){0,3}$/'],
            'latest_version_code' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'minimum_version' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\d+(\.\d+){0,3}$/'],
            'force_update' => ['sometimes', 'boolean'],
            'download_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'release_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
