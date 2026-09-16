<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\AccountLock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    private bool $forceIncludePermissions = false;

    /**
     * Always include the `permissions` list, regardless of who is asking.
     * Used for the login / me responses, where the payload IS the caller.
     */
    public function includePermissions(): static
    {
        $this->forceIncludePermissions = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'employee_id' => $this->employee_id,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'phone' => $this->phone,
            'address' => $this->address,
            'sex' => $this->sex,
            'role' => $this->role->value,
            'must_change_password' => (bool) $this->must_change_password,
            'has_void_pin' => $this->hasVoidPin(),
            'has_pin' => $this->hasPin(),
            'is_locked' => AccountLock::isLocked($this->resource),
            'lock_type' => $this->lock_type,
            'is_super_admin' => $this->isSuperAdmin(),
            'is_company_admin' => $this->isCompanyAdmin(),
            'access_role' => $this->whenLoaded('accessRole', fn () => $this->accessRole ? [
                'id' => $this->accessRole->id,
                'key' => $this->accessRole->key,
                'name' => $this->accessRole->name,
            ] : null),
            'driver_id' => $this->driver_id,
            // The driver this conductor must supply a Driver Code for at
            // login — see AuthController::login().
            'driver' => $this->whenLoaded('driver', fn () => $this->driver ? [
                'id' => $this->driver->id,
                'name' => $this->driver->name,
                'driver_code' => $this->driver->driver_code,
            ] : null),
            'company' => $this->whenLoaded('company', fn () => $this->company ? [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'code' => $this->company->code,
                'status' => $this->company->status->value,
                'can_create_accounts' => $this->company->can_create_accounts,
                // Plain branding — visible to any member of the company,
                // not gated behind company.settings.view/manage (which
                // controls who may change it, not who may see it).
                'logo_url' => $this->company->relationLoaded('settings') ? $this->company->settings?->logo_url : null,
            ] : null),
            'company_id' => $this->company_id,
            // The caller's own permission keys, or when explicitly asked for.
            'permissions' => $this->when(
                $this->forceIncludePermissions || $this->isSelf($request) || $request->boolean('with_permissions'),
                fn () => $this->grantedPermissionKeys()->values(),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function isSelf(Request $request): bool
    {
        return $request->user() !== null && $request->user()->is($this->resource);
    }
}
