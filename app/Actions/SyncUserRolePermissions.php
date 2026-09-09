<?php

namespace App\Actions;

use App\Models\Permission;
use App\Models\User;

/**
 * Copies a user's role default grants into `user_permissions` — BITS'
 * AccountDefaults step at account creation.
 *
 * `user_permissions` stays the live source of truth afterwards, so an admin
 * can add or revoke individual keys without touching the role.
 */
class SyncUserRolePermissions
{
    /**
     * @param  bool  $reset  true = the user's grants become exactly the
     *                       role's defaults (drops any custom grants);
     *                       false = the role's defaults are added, existing
     *                       custom grants are kept.
     */
    public function handle(User $user, bool $reset = false): void
    {
        $user->loadMissing('accessRole');

        $roleKeys = $user->accessRole
            ? $user->accessRole->grantedPermissionKeys()->all()
            : [];

        $ids = Permission::query()->whereIn('permission_key', $roleKeys)->pluck('id');
        $grants = $ids->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])->all();

        if ($reset) {
            $user->permissions()->sync($grants);
        } else {
            $user->permissions()->syncWithoutDetaching($grants);
        }

        $user->forgetCachedPermissions();
    }
}
