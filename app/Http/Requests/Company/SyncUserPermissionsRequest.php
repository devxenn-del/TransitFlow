<?php

namespace App\Http\Requests\Company;

use App\Models\PermissionGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body: { "permissions": ["accounts.view", "buses.view", ...] }
 *
 * The list is the complete set of keys the user should be granted
 * afterwards (allowed = 1); anything omitted is revoked. Platform-only keys
 * are rejected so a company admin can't grant `companies.*` etc.
 */
class SyncUserPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('managePermissions', $this->route('user')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => [
                'string',
                Rule::exists('permissions', 'permission_key')
                    ->whereNotIn('permission_group_id', $this->platformGroupIds()),
            ],
        ];
    }

    /**
     * @return array<int, int>
     */
    private function platformGroupIds(): array
    {
        return PermissionGroup::query()
            ->whereIn('name', ['Companies', 'Platform Users'])
            ->pluck('id')
            ->all();
    }
}
