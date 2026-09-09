<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Actions\SyncUserRolePermissions;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreUserRequest;
use App\Http\Requests\SuperAdmin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Platform-level user administration — creating Company Admins for any
 * company and other platform accounts. All actions are Super-Admin-only
 * (`permission:platform.users.*` routes + the Gate::before bypass).
 *
 * `User::query()` is written with `withoutCompanyScope()` so it spans every
 * company regardless of whether the Super Admin has scoped into one.
 */
class UserController extends Controller
{
    public function __construct(private SyncUserRolePermissions $syncPermissions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::query()->withoutCompanyScope()
            ->with(['accessRole', 'company'])
            ->when($request->has('company_id'), fn ($q) => $q->where('company_id', $request->input('company_id') ?: null))
            ->when($request->string('role')->isNotEmpty(), fn ($q) => $q->whereRelation('accessRole', 'key', $request->string('role')))
            ->when($request->string('q')->isNotEmpty(), fn ($q) => $q->where(
                fn ($s) => $s->where('name', 'like', "%{$request->string('q')}%")->orWhere('email', 'like', "%{$request->string('q')}%")
            ))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $role = Role::query()->findOrFail($request->integer('role_id'));
        $companyId = $request->input('company_id') ?: null;

        $user = User::query()->withoutCompanyScope()->create([
            'company_id' => $companyId,
            'name' => $request->string('name'),
            'email' => $request->string('email'),
            'password' => $request->string('password')->value(),
            'role' => UserRole::forRole($role)->value,
            'role_id' => $role->id,
            'status' => $request->string('status')->value() ?: 'active',
        ]);

        $this->syncPermissions->handle($user, reset: true);

        return UserResource::make($user->load(['accessRole', 'company']))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(User $user): UserResource
    {
        return UserResource::make($user->load(['accessRole', 'company']));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $data = $request->safe()->except('role_id');

        if ($request->filled('role_id')) {
            $role = Role::query()->findOrFail($request->integer('role_id'));
            $data['role_id'] = $role->id;
            $data['role'] = UserRole::forRole($role)->value;
        }

        $user->update($data);

        if ($request->filled('role_id')) {
            $this->syncPermissions->handle($user->refresh(), reset: false);
        }

        return UserResource::make($user->fresh()->load(['accessRole', 'company']));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if($user->is($request->user()), JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'You cannot delete your own account.');

        $user->tokens()->delete();
        $user->delete();

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }
}
