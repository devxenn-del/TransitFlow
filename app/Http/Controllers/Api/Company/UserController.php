<?php

namespace App\Http\Controllers\Api\Company;

use App\Actions\SyncUserRolePermissions;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreUserRequest;
use App\Http\Requests\Company\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Support\AccountLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A company's own user accounts, managed by its Company Admin. Every query
 * runs through CompanyScope on the User model, so another company's users
 * are invisible here (404 on a hand-crafted id). Capability is gated by
 * `permission:accounts.*` on the routes; the company boundary and
 * self-protection by UserPolicy.
 */
class UserController extends Controller
{
    public function __construct(private SyncUserRolePermissions $syncPermissions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with(['accessRole', 'driver'])
            ->when($request->string('role')->isNotEmpty(), fn ($q) => $q->whereRelation('accessRole', 'key', $request->string('role')))
            ->when($request->string('status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('status')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $role = Role::query()->findOrFail($request->integer('role_id'));

        $user = User::query()->create([
            // company_id is filled by the BelongsToCompany trait from context.
            'name' => $request->string('name'),
            'email' => $request->string('email'),
            'password' => $request->string('password')->value(),
            'role' => UserRole::forRole($role)->value,
            'role_id' => $role->id,
            'status' => $request->string('status')->value() ?: 'active',
            ...$request->safe()->only(['first_name', 'middle_name', 'last_name', 'phone', 'address', 'sex', 'driver_id']),
        ]);

        if ($request->filled('pin')) {
            $user->forceFill(['pin_hash' => $request->string('pin')->value()])->save();
        }

        $this->syncPermissions->handle($user, reset: true);

        return UserResource::make($user->load(['accessRole', 'driver']))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(User $user): UserResource
    {
        $this->authorize('view', $user);

        return UserResource::make($user->load(['accessRole', 'driver']));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $data = $request->safe()->except(['role_id', 'pin']);

        if ($request->filled('role_id')) {
            $role = Role::query()->findOrFail($request->integer('role_id'));
            $data['role_id'] = $role->id;
            $data['role'] = UserRole::forRole($role)->value;
        }

        $user->update($data);

        if ($request->filled('pin')) {
            $user->forceFill(['pin_hash' => $request->string('pin')->value()])->save();
        }

        if ($request->filled('role_id')) {
            $this->syncPermissions->handle($user->refresh(), reset: false);
        }

        return UserResource::make($user->fresh()->load(['accessRole', 'driver']));
    }

    public function destroy(User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        $user->tokens()->delete();
        $user->delete();

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }

    /** Admin lock — BITS `admin/accounts.php` Lock button. Persists until explicitly unlocked. */
    public function lock(Request $request, User $user): UserResource
    {
        $this->authorize('update', $user);

        AccountLock::lockAdmin($user, $request->user());
        $user->tokens()->delete();

        return UserResource::make($user->fresh()->load('accessRole'));
    }

    public function unlock(User $user): UserResource
    {
        $this->authorize('update', $user);

        AccountLock::unlock($user);

        return UserResource::make($user->fresh()->load('accessRole'));
    }
}
