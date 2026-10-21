<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\SaveCharterBookingRequest;
use App\Http\Resources\CharterBookingResource;
use App\Models\Bus;
use App\Models\CharterBooking;
use App\Models\CharterCharge;
use App\Models\CharterPayment;
use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use App\Support\CharterConflicts;
use App\Support\CharterLedger;
use App\Support\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Charter Bookings — the office side of Special trips (charter / hire).
 * The office books the client and the crew (no double-booking —
 * App\Support\CharterConflicts), an authorized person approves it, the
 * conductor runs it from the app (App\Actions\StartTrip), and the money —
 * payments, extra charges, expenses, settlement — is recorded here
 * (App\Support\CharterLedger), apart from ticket sales and remittances.
 * Permission keys: charters.view / create / edit / approve / payments.
 * CompanyScope keeps every query inside the current company.
 */
class CharterBookingController extends Controller
{
    private const RELATIONS = ['bus', 'driver', 'conductor', 'approver', 'creator', 'settler', 'trip', 'payments.recorder', 'charges.recorder'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = $request->string('q')->trim()->value();

        return CharterBookingResource::collection(
            CharterBooking::query()
                ->with(['bus', 'driver', 'conductor', 'payments', 'charges'])
                ->when($request->string('status')->isNotEmpty(), fn ($query) => $query->where('status', $request->string('status')->value()))
                ->when($request->filled('from'), fn ($query) => $query->where('departure_at', '>=', Carbon::parse($request->input('from'))->startOfDay()))
                ->when($request->filled('to'), fn ($query) => $query->where('departure_at', '<=', Carbon::parse($request->input('to'))->endOfDay()))
                ->when($q !== '', fn ($query) => $query->where(fn ($s) => $s
                    ->where('client_name', 'like', "%{$q}%")
                    ->orWhere('reference', 'like', "%{$q}%")
                    ->orWhere('destination', 'like', "%{$q}%")))
                ->orderByDesc('departure_at')
                ->paginate($request->integer('per_page', 20))
        );
    }

    public function show(CharterBooking $charter): CharterBookingResource
    {
        return CharterBookingResource::make($charter->load(self::RELATIONS));
    }

    public function store(SaveCharterBookingRequest $request): JsonResponse
    {
        $data = $request->validated();
        CharterConflicts::assertFree(Carbon::parse($data['departure_at']), Carbon::parse($data['return_at']),
            (int) $data['bus_id'], (int) $data['driver_id'], (int) $data['conductor_id']);

        $charter = CharterBooking::query()->create([...$data, 'status' => 'Pending', 'created_by' => $request->user()->id]);
        Audit::record('charter.created', $charter, ['reference' => $charter->fresh()->reference, 'client' => $charter->client_name]);

        return CharterBookingResource::make($charter->fresh()->load(self::RELATIONS))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /**
     * Only while Pending / Approved. Changing an Approved booking's crew or
     * times sends it back to Pending for re-approval.
     */
    public function update(SaveCharterBookingRequest $request, CharterBooking $charter): CharterBookingResource
    {
        $this->requireStatus($charter, CharterBooking::EDITABLE, 'Only a pending or approved booking can be edited.');

        $data = $request->validated();
        CharterConflicts::assertFree(Carbon::parse($data['departure_at']), Carbon::parse($data['return_at']),
            (int) $data['bus_id'], (int) $data['driver_id'], (int) $data['conductor_id'], $charter->id);

        $charter->fill($data);
        $needsReapproval = $charter->status === 'Approved'
            && $charter->isDirty(['bus_id', 'driver_id', 'conductor_id', 'departure_at', 'return_at']);
        if ($needsReapproval) {
            $charter->fill(['status' => 'Pending', 'approved_by' => null, 'approved_at' => null]);
        }
        $charter->save();
        Audit::record('charter.updated', $charter, ['reference' => $charter->reference, 'reapproval' => $needsReapproval]);

        return CharterBookingResource::make($charter->load(self::RELATIONS));
    }

    public function approve(Request $request, CharterBooking $charter): CharterBookingResource
    {
        $this->requireStatus($charter, ['Pending'], 'Only a pending booking can be approved.');
        // Re-checked: something may have been booked since this was created.
        CharterConflicts::assertFree($charter->departure_at, $charter->return_at,
            $charter->bus_id, $charter->driver_id, $charter->conductor_id, $charter->id);

        $charter->update(['status' => 'Approved', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'rejected_reason' => null]);
        Audit::record('charter.approved', $charter, ['reference' => $charter->reference]);

        return CharterBookingResource::make($charter->load(self::RELATIONS));
    }

    public function reject(Request $request, CharterBooking $charter): CharterBookingResource
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];
        $this->requireStatus($charter, ['Pending'], 'Only a pending booking can be rejected.');

        $charter->update(['status' => 'Rejected', 'rejected_reason' => $reason]);
        Audit::record('charter.rejected', $charter, ['reference' => $charter->reference, 'reason' => $reason]);

        return CharterBookingResource::make($charter->load(self::RELATIONS));
    }

    public function cancel(Request $request, CharterBooking $charter): CharterBookingResource
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];
        $this->requireStatus($charter, CharterBooking::EDITABLE, 'Only a pending or approved booking can be cancelled — a started charter is ended from the app.');

        $charter->update(['status' => 'Cancelled', 'cancelled_reason' => $reason]);
        Audit::record('charter.cancelled', $charter, ['reference' => $charter->reference, 'reason' => $reason]);

        return CharterBookingResource::make($charter->load(self::RELATIONS));
    }

    /**
     * The booking form's crew pickers for a window: every Active bus, driver
     * and conductor, each with `busy` = why it's taken (null = free).
     */
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'departure_at' => ['required', 'date'],
            'return_at' => ['required', 'date', 'after:departure_at'],
            'except' => ['nullable', 'integer'],
        ]);
        $busy = CharterConflicts::busy(Carbon::parse($data['departure_at']), Carbon::parse($data['return_at']),
            isset($data['except']) ? (int) $data['except'] : null);
        $companyId = app(CompanyContext::class)->companyId();

        return response()->json([
            'buses' => Bus::query()->where('status', 'Active')->orderBy('bus_number')->get(['id', 'bus_number', 'plate_number', 'capacity'])
                ->map(fn ($b) => [...$b->only(['id', 'bus_number', 'plate_number', 'capacity']), 'busy' => $busy['bus'][$b->id] ?? null]),
            'drivers' => Driver::query()->where('status', 'Active')->orderBy('name')->get(['id', 'name', 'driver_code'])
                ->map(fn ($d) => [...$d->only(['id', 'name', 'driver_code']), 'busy' => $busy['driver'][$d->id] ?? null]),
            'conductors' => User::query()
                ->where('company_id', $companyId)
                ->whereIn('role_id', Role::query()->forCompany($companyId)->where('key', 'conductor')->select('id'))
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => [...$u->only(['id', 'name']), 'busy' => $busy['conductor'][$u->id] ?? null]),
        ]);
    }

    /* ------------------------------ money ------------------------------ */

    public function storePayment(Request $request, CharterBooking $charter): CharterBookingResource
    {
        $this->requireOpenMoney($charter);
        $data = $request->validate([
            'type' => ['required', Rule::in(CharterPayment::TYPES)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999.99'],
            'method' => ['required', Rule::in(CharterPayment::METHODS)],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $payment = $charter->payments()->create([
            ...$data,
            'company_id' => $charter->company_id,
            'paid_at' => $data['paid_at'] ?? now(),
            'recorded_by' => $request->user()->id,
        ]);
        Audit::record('charter.payment.recorded', $charter, ['reference' => $charter->reference, 'type' => $payment->type, 'amount' => (float) $payment->amount]);

        return CharterBookingResource::make($charter->fresh()->load(self::RELATIONS));
    }

    public function destroyPayment(CharterBooking $charter, int $payment): CharterBookingResource
    {
        $this->requireOpenMoney($charter);
        $row = $charter->payments()->findOrFail($payment);
        $row->delete();
        Audit::record('charter.payment.deleted', $charter, ['reference' => $charter->reference, 'type' => $row->type, 'amount' => (float) $row->amount]);

        return CharterBookingResource::make($charter->fresh()->load(self::RELATIONS));
    }

    /** A Charge (billed to the client) or an Expense (the company's cost). */
    public function storeCharge(Request $request, CharterBooking $charter): CharterBookingResource
    {
        $this->requireOpenMoney($charter);
        $data = $request->validate([
            'kind' => ['required', Rule::in(CharterCharge::KINDS)],
            'category' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999.99'],
            'incurred_at' => ['nullable', 'date'],
        ]);

        $charge = $charter->charges()->create([
            ...$data,
            'company_id' => $charter->company_id,
            'incurred_at' => $data['incurred_at'] ?? now(),
            'recorded_by' => $request->user()->id,
        ]);
        Audit::record('charter.'.strtolower($charge->kind).'.recorded', $charter, ['reference' => $charter->reference, 'category' => $charge->category, 'amount' => (float) $charge->amount]);

        return CharterBookingResource::make($charter->fresh()->load(self::RELATIONS));
    }

    public function destroyCharge(CharterBooking $charter, int $charge): CharterBookingResource
    {
        $this->requireOpenMoney($charter);
        $row = $charter->charges()->findOrFail($charge);
        $row->delete();
        Audit::record('charter.'.strtolower($row->kind).'.deleted', $charter, ['reference' => $charter->reference, 'category' => $row->category, 'amount' => (float) $row->amount]);

        return CharterBookingResource::make($charter->fresh()->load(self::RELATIONS));
    }

    /** Final settlement: the trip is done and the client owes nothing (and isn't owed). Locks the money. */
    public function settle(Request $request, CharterBooking $charter): CharterBookingResource
    {
        $this->requireStatus($charter, ['Completed'], 'Only a completed charter (its trip ended) can be settled.');
        $balance = CharterLedger::for($charter)->balance();
        if (abs($balance) >= 0.005) {
            throw ValidationException::withMessages(['balance' => $balance > 0
                ? 'The client still owes ₱'.number_format($balance, 2).' — record the payment first.'
                : 'The client has overpaid by ₱'.number_format(-$balance, 2).' — record a refund first.']);
        }

        $charter->update(['status' => 'Settled', 'settled_by' => $request->user()->id, 'settled_at' => now()]);
        Audit::record('charter.settled', $charter, ['reference' => $charter->reference, 'ledger' => CharterLedger::for($charter)->toArray()]);

        return CharterBookingResource::make($charter->load(self::RELATIONS));
    }

    /* ----------------------------- helpers ----------------------------- */

    /** @param  list<string>  $statuses */
    private function requireStatus(CharterBooking $charter, array $statuses, string $message): void
    {
        if (! in_array($charter->status, $statuses, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    /**
     * Money can be recorded from booking until settlement — and on a
     * cancelled booking (to refund its deposit), but not a rejected or
     * settled one.
     */
    private function requireOpenMoney(CharterBooking $charter): void
    {
        $this->requireStatus($charter, ['Pending', 'Approved', 'InProgress', 'Completed', 'Cancelled'],
            'This booking is '.strtolower($charter->status).' — its money can no longer be changed.');
    }
}
