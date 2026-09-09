<?php

namespace App\Http\Requests\Company;

use App\Models\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
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

        return [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('roles', 'name')->where('company_id', $this->user()?->company_id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permission_keys' => ['sometimes', 'array'],
            'permission_keys.*' => ['string', Rule::in($assignable)],
        ];
    }
}
