<?php

namespace App\Http\Requests\Company;

use App\Models\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('roles.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $assignable = Permission::query()->companyAssignable()->pluck('permission_key')->all();
        $roleId = $this->route('role')?->id;

        return [
            'name' => [
                'sometimes', 'string', 'max:100',
                Rule::unique('roles', 'name')->where('company_id', $this->user()?->company_id)->ignore($roleId),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permission_keys' => ['sometimes', 'array'],
            'permission_keys.*' => ['string', Rule::in($assignable)],
            // Re-apply the (new) defaults to users already holding this role.
            'apply_to_users' => ['sometimes', 'boolean'],
        ];
    }
}
