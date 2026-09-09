<?php

namespace App\Http\Controllers\Api\Company;

use App\Actions\ReceiveRemittance;
use App\Actions\VoidRemittanceReceipt;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\ApproveRemittanceRequest;
use App\Http\Requests\Company\ReceiveRemittanceRequest;
use App\Http\Requests\Company\VoidRemittanceRequest;
use App\Http\Resources\DispatchResource;
use App\Http\Resources\RemittanceCashCountResource;
use App\Http\Resources\TripMonitorResource;
use App\Models\Trip;
use App\Support\Audit;
use App\Support\TripRemittance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * The company Remittance desk — BITS `office/remittances.php` /
 * `admin/remittances.php` (docs/MIGRATION_MAP.md §4.3–4.4).
 *
 * Lifecycle per trip: Remitted (conductor) → Received (a cashier counts the
 * physical cash) → Approved (a manager signs off). Voiding a received count
 * is manager-only and gated by the void-PIN.
 */
class RemittanceController extends Controller
{
    public function __construct(
        private readonly ReceiveRemittance $receiveAction,
        private readonly VoidRemittanceReceipt $voidAction,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Trip::class);

        $stage = $request->string('stage')->value();

        $trips = Trip::query()
            ->where('status', 'Arrived')
            ->with(['conductor', 'driver', 'remittanceApprovedBy', 'remittanceCashCount'])
            ->withCount('tickets')
            ->when($stage === 'pending', fn ($q) => $q->whereNull('remittance_received_at'))
            ->when($stage === 'received', fn ($q) => $q->whereNotNull('remittance_received_at')->whereNull('remittance_approved_at'))
            ->when($stage === 'approved', fn ($q) => $q->whereNotNull('remittance_approved_at'))
            ->when($request->boolean('flagged'), fn ($q) => $q->where('remittance_flagged', true))
            ->when($request->filled('bus_id'), fn ($q) => $q->where('bus_id', $request->integer('bus_id')))
            ->when($request->filled('conductor_id'), fn ($q) => $q->where('conductor_id', $request->integer('conductor_id')))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('op_date', $request->date('date')))
            ->orderByRaw('CASE WHEN remittance_received_at IS NULL THEN 0 WHEN remittance_approved_at IS NULL THEN 1 ELSE 2 END')
            ->orderByDesc('ended_at')
            ->paginate($request->integer('per_page', 20));

        return TripMonitorResource::collection($trips);
    }

    public function show(Trip $trip): JsonResponse
    {
        $this->authorize('view', $trip);

        $trip->load(['conductor', 'driver', 'bus', 'remittanceApprovedBy'])->loadCount('tickets');
        $rcc = $trip->remittanceCashCount()->with('voidedBy')->first();

        return response()->json([
            'data' => TripMonitorResource::make($trip),
            'cash_count' => $rcc ? RemittanceCashCountResource::make($rcc) : null,
            'dispatches' => DispatchResource::collection($trip->dispatches()->orderByDesc('id')->get()),
        ]);
    }

    /** Take (or refresh) the short-lived processing lock on this remittance. */
    public function lock(Request $request, Trip $trip): JsonResponse
    {
        $this->authorize('view', $trip);
        $this->assertNotLockedByOther($request, $trip);

        $trip->update([
            'remittance_locked_by' => $request->user()->id,
            'remittance_locked_at' => now(),
        ]);

        return response()->json([
            'locked_by_me' => true,
            'expires_at' => now()->addMinutes(Trip::REMITTANCE_LOCK_MINUTES),
        ]);
    }

    /** Release the lock (only the holder or an expired lock). */
    public function unlock(Request $request, Trip $trip): JsonResponse
    {
        $this->authorize('view', $trip);

        if (! $trip->remittanceLockedByOther($request->user()->id)) {
            $trip->update(['remittance_locked_by' => null, 'remittance_locked_at' => null]);
        }

        return response()->json(['released' => true]);
    }

    public function receive(ReceiveRemittanceRequest $request, Trip $trip): JsonResponse
    {
        $this->authorize('view', $trip);
        $this->assertNotLockedByOther($request, $trip);

        $rcc = $this->receiveAction->handle($request->user(), $trip, $request->validated());
        $trip->update(['remittance_locked_by' => null, 'remittance_locked_at' => null]);

        return response()->json([
            'data' => RemittanceCashCountResource::make($rcc),
            'remittance' => TripRemittance::for($trip->fresh())->toArray(),
        ], JsonResponse::HTTP_CREATED);
    }

    public function void(VoidRemittanceRequest $request, Trip $trip): JsonResponse
    {
        $this->authorize('view', $trip);
        $this->assertNotLockedByOther($request, $trip);

        $rcc = $trip->remittanceCashCount()->received()->firstOrFail();
        $rcc = $this->voidAction->handle(
            $request->user(),
            $rcc,
            $request->string('reason')->value(),
            $request->input('pin'),
        );

        Audit::record('remittance.voided', $trip, [
            'reason' => $request->string('reason')->value(),
        ]);

        return response()->json([
            'data' => RemittanceCashCountResource::make($rcc),
            'remittance' => TripRemittance::for($trip->fresh())->toArray(),
        ]);
    }

    public function approve(ApproveRemittanceRequest $request, Trip $trip): TripMonitorResource
    {
        $this->authorize('view', $trip);

        if ($trip->status !== 'Arrived') {
            throw ValidationException::withMessages(['trip' => 'Only a completed trip can be approved.']);
        }
        if ($trip->remittance_received_at === null) {
            throw ValidationException::withMessages(['trip' => 'Receive the remittance before approving it.']);
        }
        if ($trip->remittanceIsApproved()) {
            throw ValidationException::withMessages(['trip' => 'This remittance is already approved.']);
        }

        $counted = (int) ($trip->remittanceCashCount()->received()->value('counted_total') ?? 0);
        $variance = $counted - (float) $trip->remitted_amount;

        $trip->update([
            'remittance_excess_amount' => max(0, $variance),
            'remittance_short_amount' => max(0, -$variance),
            'remittance_approved_at' => now(),
            'remittance_approved_by' => $request->user()->id,
            'remittance_note' => $request->string('note')->value() ?: null,
        ]);

        Audit::record('remittance.approved', $trip, [
            'remitted_amount' => (float) $trip->remitted_amount,
            'excess' => max(0, $variance),
            'short' => max(0, -$variance),
        ]);

        return TripMonitorResource::make(
            $trip->fresh()->load(['conductor', 'driver', 'remittanceApprovedBy'])->loadCount('tickets')
        );
    }

    public function flag(Request $request, Trip $trip): TripMonitorResource
    {
        $this->authorize('view', $trip);
        abort_unless($request->user()->hasPermissionTo('remittances.approve'), 403);

        $data = $request->validate([
            'flagged' => ['required', 'boolean'],
            'note' => ['required_if:flagged,true', 'nullable', 'string', 'max:255'],
        ]);

        $trip->update([
            'remittance_flagged' => $data['flagged'],
            'remittance_flag_note' => $data['flagged'] ? $data['note'] : null,
        ]);

        return TripMonitorResource::make(
            $trip->fresh()->load(['conductor', 'driver', 'remittanceApprovedBy'])->loadCount('tickets')
        );
    }

    private function assertNotLockedByOther(Request $request, Trip $trip): void
    {
        if ($trip->remittanceLockedByOther($request->user()->id)) {
            $who = $trip->remittanceLockedBy()->value('name') ?? 'Someone';
            abort(JsonResponse::HTTP_LOCKED, "{$who} is processing this remittance right now.");
        }
    }
}
