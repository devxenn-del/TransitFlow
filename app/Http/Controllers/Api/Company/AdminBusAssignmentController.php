<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreAdminBusAssignmentRequest;
use App\Http\Requests\Company\UpdateAdminBusAssignmentRequest;
use App\Http\Resources\AdminBusAssignmentResource;
use App\Models\AdminBusAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Office-admin ⇄ bus assignments — BITS `admin/adminassignments.php`
 * (docs/MIGRATION_MAP.md §4.4). Every query runs through CompanyScope.
 *
 * Report/remittance scoping by these assignments (BITS `App\AdminBusAccess`)
 * is a deliberately deferred follow-up (docs/PARITY_CHECKLIST.md §J) — this
 * controller only manages the assignment records themselves.
 */
class AdminBusAssignmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AdminBusAssignment::class);

        return AdminBusAssignmentResource::collection(
            AdminBusAssignment::query()
                ->with(['bus', 'user', 'assignedBy'])
                ->when($request->filled('bus_id'), fn ($q) => $q->where('bus_id', $request->integer('bus_id')))
                ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->orderByDesc('effective_from')
                ->paginate($request->integer('per_page', 20))
        );
    }

    public function store(StoreAdminBusAssignmentRequest $request): JsonResponse
    {
        $conflict = AdminBusAssignment::query()
            ->conflicting(
                $request->integer('bus_id'),
                $request->string('shift')->value(),
                $request->string('effective_from')->value(),
                $request->filled('effective_to') ? $request->string('effective_to')->value() : null,
            )
            ->with('user')
            ->first();

        if ($conflict !== null) {
            throw ValidationException::withMessages([
                'bus_id' => "That bus is already assigned to {$conflict->user?->name} for the {$conflict->shift} shift over an overlapping date range.",
            ]);
        }

        // company_id is filled in by the BelongsToCompany trait.
        $assignment = AdminBusAssignment::query()->create([
            ...$request->validated(),
            'status' => 'Active',
            'assigned_by' => $request->user()->id,
        ])->fresh();

        return AdminBusAssignmentResource::make($assignment->load(['bus', 'user', 'assignedBy']))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(AdminBusAssignment $assignment): AdminBusAssignmentResource
    {
        $this->authorize('view', $assignment);

        return AdminBusAssignmentResource::make($assignment->load(['bus', 'user', 'assignedBy']));
    }

    public function update(UpdateAdminBusAssignmentRequest $request, AdminBusAssignment $assignment): AdminBusAssignmentResource
    {
        $data = $request->validated();

        if (($data['status'] ?? $assignment->status) === 'Active') {
            $conflict = AdminBusAssignment::query()
                ->conflicting(
                    $assignment->bus_id,
                    $data['shift'] ?? $assignment->shift,
                    $data['effective_from'] ?? $assignment->effective_from->toDateString(),
                    array_key_exists('effective_to', $data) ? $data['effective_to'] : $assignment->effective_to?->toDateString(),
                    ignoreId: $assignment->id,
                )
                ->with('user')
                ->first();

            if ($conflict !== null) {
                throw ValidationException::withMessages([
                    'bus_id' => "That bus is already assigned to {$conflict->user?->name} for the {$conflict->shift} shift over an overlapping date range.",
                ]);
            }
        }

        $assignment->update($data);

        return AdminBusAssignmentResource::make($assignment->fresh()->load(['bus', 'user', 'assignedBy']));
    }

    public function destroy(AdminBusAssignment $assignment): JsonResponse
    {
        $this->authorize('delete', $assignment);

        $assignment->delete();

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }
}
