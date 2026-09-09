<?php

namespace App\Http\Controllers\Api\Company;

use App\Actions\SyncUserRolePermissions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreRoleRequest;
use App\Http\Requests\Company\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Per-company roles — BITS' Roles admin page (docs/MIGRATION_MAP.md §5).
 * Each company owns its role set (cloned from the platform templates on
 * provisioning) and can add / edit / remove roles freely. Platform roles
 * and other companies' roles are never visible or touchable here.
 */
class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $companyId = $request->user()->company_id;
        $disabled = $company?->disabledPermissionKeys() ?? collect();

        $roles = Role::query()
            ->forCompany($companyId)
            ->with('permissions')
            ->withCount(['users' => fn ($q) => $q->where('company_id', $companyId)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => RoleResource::collection($roles),
            'catalogue' => Permission::query()->companyAssignable()
                ->with('group')
                ->orderBy('permission_group_id')->orderBy('nav_order')->orderBy('name')
                ->get()
                ->groupBy(fn (Permission $p) => $p->group->name)
                ->map(fn ($group) => $group->map(fn (Permission $p) => [
                    'key' => $p->permission_key,
                    'name' => $p->name,
                    'available' => ! $disabled->contains($p->permission_key),
                ])->values()),
            'disabled_permissions' => $disabled->values(),
        ]);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $role = DB::transaction(function () use ($request, $companyId) {
            $role = Role::query()->create([
                'company_id' => $companyId,
                'key' => $this->uniqueKey($companyId, $request->string('name')->value()),
                'name' => $request->string('name')->value(),
                'description' => $request->input('description'),
                'is_platform' => false,
                'is_admin' => false,
                'sort_order' => (int) Role::query()->forCompany($companyId)->max('sort_order') + 10,
            ]);

            $this->syncGrants($role, $request->input('permission_keys', []));

            return $role;
        });

        Audit::record('role.created', $role, ['grants' => $role->permissions()->pluck('permission_key')->all()]);

        return RoleResource::make($role->load('permissions')->loadCount('users'))
            ->response()->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(Request $request, Role $role): RoleResource
    {
        $this->authorizeCompany($request, $role);

        return RoleResource::make($role->load('permissions')->loadCount('users'));
    }

    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        $this->authorizeCompany($request, $role);

        $role->update($request->only(['name', 'description']));

        if ($request->has('permission_keys')) {
            $this->syncGrants($role, $request->input('permission_keys', []));

            if ($request->boolean('apply_to_users')) {
                $sync = app(SyncUserRolePermissions::class);
                $role->users()->where('company_id', $role->company_id)->get()
                    ->each(fn (User $u) => $sync->handle($u, reset: false));
            }
        }

        Audit::record('role.updated', $role, [
            'grants' => $role->permissions()->pluck('permission_key')->all(),
            'applied_to_users' => $request->boolean('apply_to_users'),
        ]);

        return RoleResource::make($role->fresh()->load('permissions')->loadCount('users'));
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->authorizeCompany($request, $role);

        if ($role->is_admin) {
            throw ValidationException::withMessages(['role' => 'The company admin role cannot be deleted.']);
        }
        if ($role->users()->where('company_id', $role->company_id)->exists()) {
            throw ValidationException::withMessages(['role' => 'Reassign the users on this role first.']);
        }

        Audit::record('role.deleted', $role);

        $role->permissions()->detach();
        $role->delete();

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }

    private function authorizeCompany(Request $request, Role $role): void
    {
        abort_unless($role->company_id === $request->user()->company_id, 404);
    }

    private function uniqueKey(int $companyId, string $name): string
    {
        $base = Str::slug($name, '_') ?: 'role';
        $key = $base;
        $n = 1;
        while (Role::query()->forCompany($companyId)->where('key', $key)->exists()) {
            $key = $base.'_'.(++$n);
        }

        return $key;
    }

    /**
     * @param  list<string>  $keys
     */
    private function syncGrants(Role $role, array $keys): void
    {
        $disabled = $role->company?->disabledPermissionKeys() ?? collect();

        $ids = Permission::query()->companyAssignable()
            ->whereIn('permission_key', $keys)
            ->whereNotIn('permission_key', $disabled->all())
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();

        $role->permissions()->sync($ids);
    }
}
