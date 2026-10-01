<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreCompanyFeeRateRequest;
use App\Http\Requests\SuperAdmin\UpdateCompanyFeeRateRequest;
use App\Http\Resources\CompanyFeeRateResource;
use App\Models\Company;
use App\Models\CompanyFeeRate;
use App\Models\Fee;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;

/**
 * Special (company-specific) pricing for one company — Super Admin only.
 * A rate here never touches the fee's standard amount or another company's
 * price; it takes effect only while the company is on the Special plan
 * (App\Support\Billing\FeePricing). Every change is audited with its
 * previous and new values.
 */
class CompanySpecialRateController extends Controller
{
    public function store(StoreCompanyFeeRateRequest $request, Company $company): JsonResponse
    {
        $fee = Fee::query()->findOrFail($request->integer('fee_id'));

        $rate = $company->feeRates()->create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ])->refresh();

        // A special rate for a fee the company isn't billed yet assigns it.
        if (! $fee->applies_to_all_companies && ! $company->assignedFees()->whereKey($fee->id)->exists()) {
            $company->assignedFees()->attach($fee->id);
            Audit::record('billing.fee.assigned', $fee, [
                'fee' => $fee->name,
                'standard_amount' => (string) $fee->amount,
            ], company: $company);
        }

        Audit::record('billing.special_rate.created', $rate, [
            'fee' => $fee->name,
            'standard_amount' => (string) $fee->amount,
            'new' => $this->snapshot($rate),
        ], company: $company);

        return CompanyFeeRateResource::make($rate->load('fee'))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function update(UpdateCompanyFeeRateRequest $request, Company $company, CompanyFeeRate $feeRate): CompanyFeeRateResource
    {
        $feeRate->fill([...$request->validated(), 'updated_by' => $request->user()->id])->save();

        $changes = Audit::changes($feeRate);

        if ($changes !== []) {
            Audit::record('billing.special_rate.updated', $feeRate, [
                'fee' => $feeRate->fee->name,
                'changes' => $changes,
            ], company: $company);
        }

        return CompanyFeeRateResource::make($feeRate->load('fee'));
    }

    /**
     * Removing a special rate returns the company to the standard price for
     * that fee (unless another of its special rates is in effect).
     */
    public function destroy(Company $company, CompanyFeeRate $feeRate): JsonResponse
    {
        $this->authorize('fees.manage');

        $feeRate->delete();

        Audit::record('billing.special_rate.removed', $feeRate, [
            'fee' => $feeRate->fee->name,
            'previous' => $this->snapshot($feeRate),
        ], company: $company);

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(CompanyFeeRate $rate): array
    {
        return [
            'amount' => (string) $rate->amount,
            'starts_on' => $rate->starts_on->toDateString(),
            'ends_on' => $rate->ends_on?->toDateString(),
            'is_active' => $rate->is_active,
            'internal_notes' => $rate->internal_notes,
        ];
    }
}
