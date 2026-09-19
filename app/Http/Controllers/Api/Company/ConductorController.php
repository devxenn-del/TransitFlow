<?php

namespace App\Http\Controllers\Api\Company;

use App\Actions\SyncUserRolePermissions;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreConductorRequest;
use App\Http\Requests\Company\UpdateConductorRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * A company's conductor accounts — a Fleet-section view over the same User
 * records Company > Users manages, scoped to the "conductor" role so it can
 * be granted (`conductors.*`) without full account-management access.
 * Every query still runs through CompanyScope on the User model.
 */
class ConductorController extends Controller
{
    public function __construct(private SyncUserRolePermissions $syncPermissions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $conductors = User::query()
            ->with('accessRole')
            ->whereRelation('accessRole', 'key', 'conductor')
            ->when($request->string('status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('status')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return UserResource::collection($conductors);
    }

    public function store(StoreConductorRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $role = $this->conductorRole($request->user()->company_id);

        $conductor = User::query()->create([
            // company_id is filled by the BelongsToCompany trait from context.
            'name' => $request->string('name'),
            'email' => $request->string('email'),
            'password' => $request->string('password')->value(),
            'role' => UserRole::forRole($role)->value,
            'role_id' => $role->id,
            'status' => $request->string('status')->value() ?: 'active',
            ...$request->safe()->only(['phone', 'address', 'sex']),
        ]);

        if ($request->filled('pin')) {
            $conductor->forceFill(['pin_hash' => $request->string('pin')->value()])->save();
        }

        $this->syncPermissions->handle($conductor, reset: true);

        return UserResource::make($conductor->load('accessRole'))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(User $conductor): UserResource
    {
        $this->authorize('view', $conductor);

        return UserResource::make($conductor->load('accessRole'));
    }

    public function update(UpdateConductorRequest $request, User $conductor): UserResource
    {
        $conductor->update($request->safe()->except('pin'));

        if ($request->filled('pin')) {
            $conductor->forceFill(['pin_hash' => $request->string('pin')->value()])->save();
        }

        return UserResource::make($conductor->fresh()->load('accessRole'));
    }

    public function destroy(User $conductor): JsonResponse
    {
        $this->authorize('delete', $conductor);

        $conductor->tokens()->delete();
        $conductor->delete();

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }

    private function conductorRole(?int $companyId): Role
    {
        $role = $companyId !== null
            ? Role::query()->forCompany($companyId)->where('key', 'conductor')->first()
            : null;

        if ($role === null) {
            throw ValidationException::withMessages([
                'name' => 'This company has no Conductor role. Recreate one from the Roles page first.',
            ]);
        }

        return $role;
    }
}
