<?php

namespace App\Http\Controllers\Api\Company;

use App\Actions\SyncUserRolePermissions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\SyncUserPermissionsRequest;
use App\Http\Resources\PermissionGroupResource;
use App\Http\Resources\UserResource;
use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * The BITS "Permissions" page: view the permission catalogue and set an
 * individual user's grants. `user_permissions` is the live source of
 * truth, so edits here take effect on the user's next request.
 */
class PermissionController extends Controller
{
    public function __construct(private SyncUserRolePermissions $syncPermissions) {}

    /**
     * The permission catalogue a Company Admin may assign — platform-only
     * groups (Companies, Platform Users) are excluded.
     */
    public function catalogue(Request $request): AnonymousResourceCollection
    {
        $groups = PermissionGroup::query()
            ->whereNotIn('name', ['Companies', 'Platform Users'])
            ->with('permissions')
            ->orderBy('sort_order')
            ->get();

        return PermissionGroupResource::collection($groups)->additional([
            'disabled_permissions' => $request->user()->company?->disabledPermissionKeys()->values() ?? [],
        ]);
    }

    /**
     * One user's current grants plus their role's defaults, for the matrix.
     */
    public function showUser(User $user): array
    {
        $this->authorize('managePermissions', $user);

        $user->load('accessRole');

        return [
            'user' => UserResource::make($user),
            'granted' => $user->grantedPermissionKeys()->values(),
            'role_defaults' => $user->accessRole?->grantedPermissionKeys()->values() ?? [],
        ];
    }

    public function syncUser(SyncUserPermissionsRequest $request, User $user): array
    {
        /** @var list<string> $keys */
        $keys = $request->validated('permissions');

        // A Company Admin can only grant keys that are company-assignable AND
        // not switched off for this company by the Super Admin.
        $disabled = $user->company?->disabledPermissionKeys()->all() ?? [];

        $ids = Permission::query()
            ->companyAssignable()
            ->whereIn('permission_key', $keys)
            ->whereNotIn('permission_key', $disabled)
            ->pluck('id');
        $grants = $ids->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])->all();

        DB::transaction(fn () => $user->permissions()->sync($grants));
        $user->forgetCachedPermissions();

        Audit::record('user.permissions.synced', $user, [
            'granted' => $user->grantedPermissionKeys()->values()->all(),
        ]);

        return [
            'user' => UserResource::make($user->load('accessRole')),
            'granted' => $user->grantedPermissionKeys()->values(),
        ];
    }

    /**
     * Reset a user's grants back to exactly their role's defaults.
     */
    public function resetUser(Request $request, User $user): array
    {
        $this->authorize('managePermissions', $user);

        $this->syncPermissions->handle($user, reset: true);

        Audit::record('user.permissions.reset', $user);

        return [
            'user' => UserResource::make($user->load('accessRole')),
            'granted' => $user->grantedPermissionKeys()->values(),
        ];
    }
}
