<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreFeeRequest;
use App\Http\Requests\SuperAdmin\UpdateFeeRequest;
use App\Http\Requests\SuperAdmin\UpdateFeeStatusRequest;
use App\Http\Resources\CompanyFeeResource;
use App\Http\Resources\FeeResource;
use App\Models\Fee;
use App\Support\Audit;
use App\Support\Billing\FeePricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Fee Management — the platform fee catalogue and its standard pricing.
 * Super Admin only (`permission:fees.*` routes + the form requests).
 *
 * Every change is audited with its previous and new values. Editing a fee
 * changes future billing only: statements already generated keep the
 * amounts they snapshotted.
 */
class FeeController extends Controller
{
    /**
     * Audit action per changed column; anything not listed is a plain update.
     *
     * @var array<string, string>
     */
    private const CHANGE_ACTIONS = [
        'amount' => 'billing.fee.standard_amount_changed',
        'effective_date' => 'billing.fee.effective_date_changed',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $fees = Fee::query()
            ->withCount(['assignedCompanies', 'specialRates'])
            ->when($request->string('q')->isNotEmpty(), fn ($q) => $q->where('name', 'like', "%{$request->string('q')}%"))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->get();

        return FeeResource::collection($fees);
    }

    public function store(StoreFeeRequest $request): JsonResponse
    {
        $fee = Fee::query()->create([
            ...$request->feeAttributes(),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ])->refresh();

        Audit::record('billing.fee.created', $fee, [
            'new' => $this->snapshot($fee),
        ]);

        return FeeResource::make($fee)->response()->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /**
     * The fee plus every company it is billed to, with what each one pays.
     */
    public function show(Fee $fee, FeePricing $pricing): JsonResponse
    {
        $fee->loadCount(['assignedCompanies', 'specialRates']);

        return response()->json([
            'data' => FeeResource::make($fee),
            'companies' => $pricing->companiesForFee($fee)->map(fn (array $row) => [
                'id' => $row['company']->id,
                'name' => $row['company']->name,
                'code' => $row['company']->code,
                'status' => $row['company']->status->value,
                'pricing_plan' => $row['company']->pricing_plan->value,
                'pricing' => CompanyFeeResource::make($row['resolved']),
            ]),
        ]);
    }

    public function update(UpdateFeeRequest $request, Fee $fee): FeeResource
    {
        $fee->fill([...$request->feeAttributes(), 'updated_by' => $request->user()->id])->save();

        $this->auditChanges($fee, Audit::changes($fee));

        return FeeResource::make($fee->loadCount(['assignedCompanies', 'specialRates']));
    }

    /**
     * Enable / disable. A disabled fee drops out of billing for every
     * company until re-enabled; its configuration is kept.
     */
    public function updateStatus(UpdateFeeStatusRequest $request, Fee $fee): FeeResource
    {
        $fee->update(['is_active' => $request->boolean('is_active'), 'updated_by' => $request->user()->id]);

        $this->auditChanges($fee, Audit::changes($fee));

        return FeeResource::make($fee->loadCount(['assignedCompanies', 'specialRates']));
    }

    /**
     * Soft-deletes the fee: no further billing, but past statements keep
     * their snapshotted lines.
     */
    public function destroy(Fee $fee): JsonResponse
    {
        $this->authorize('fees.manage');

        $fee->delete();

        Audit::record('billing.fee.deleted', $fee, [
            'previous' => $this->snapshot($fee),
        ]);

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * One audit row per kind of change — standard amount, effective date,
     * enabled/disabled — and one for everything else.
     *
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     */
    private function auditChanges(Fee $fee, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        foreach (self::CHANGE_ACTIONS as $column => $action) {
            if (isset($changes[$column])) {
                Audit::record($action, $fee, ['changes' => [$column => $changes[$column]]]);
                unset($changes[$column]);
            }
        }

        if (isset($changes['is_active'])) {
            Audit::record($fee->is_active ? 'billing.fee.enabled' : 'billing.fee.disabled', $fee, [
                'changes' => ['is_active' => $changes['is_active']],
            ]);
            unset($changes['is_active']);
        }

        if ($changes !== []) {
            Audit::record('billing.fee.updated', $fee, ['changes' => $changes]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Fee $fee): array
    {
        return [
            'name' => $fee->name,
            'amount' => (string) $fee->amount,
            'billing_frequency' => $fee->frequencyLabel(),
            'applies_to_all_companies' => $fee->applies_to_all_companies,
            'is_active' => $fee->is_active,
            'effective_date' => $fee->effective_date->toDateString(),
        ];
    }
}
