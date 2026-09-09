<?php

namespace App\Http\Requests\Conductor;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The mobile app registering / heart-beating a conductor's device — BITS
 * `api/devices/register.php` (docs/MIGRATION_MAP.md §K).
 */
class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('devices.register') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'device_uuid' => ['required', 'string', 'max:191'],
            'platform' => ['sometimes', Rule::in(Device::PLATFORMS)],
            'model' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:40'],
            'push_token' => ['nullable', 'string', 'max:512'],
        ];
    }
}
