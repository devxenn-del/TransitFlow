<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Format/security rules live in App\Support\ServerConfig::validateFormat()
 * (shared with the connection test) — this just requires the field.
 */
class UpdateServerConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('system.configuration.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'api_base_url' => ['required', 'string', 'max:255'],
        ];
    }
}
