<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Super Admin creating a platform account (another Super Admin) or a
 * company's user of any role — most usefully a Company Admin.
 * Authorization is the `permission:platform.users.create` route middleware
 * plus the Gate::before Super-Admin bypass.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'role_id' => ['required', Rule::exists('roles', 'id')],
            // Null / omitted => a platform account. Otherwise must be a real company.
            'company_id' => ['nullable', Rule::exists('companies', 'id')],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
