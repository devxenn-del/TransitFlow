<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\UpdateCompanyPricingPlanRequest;
use App\Http\Resources\CompanyFeeRateResource;
use App\Http\Resources\CompanyFeeResource;
use App\Models\Company;
use App\Models\Fee;
use App\Support\Audit;
use App\Support\Billing\FeePricing;
use Illuminate\Http\JsonResponse;

/**
 * A company's pricing configuration (company workspace → Pricing
 * Configuration): its plan (Standard / Special), the price it pays per fee
 * today, and every special rate on file with its internal notes.
 * Super Admin only.
 */
class CompanyPricingController extends Controller
{
    public function show(Company $company, FeePricing $pricing): JsonResponse
    {
        return response()->json($this->payload($company, $pricing));
    }

    public function update(UpdateCompanyPricingPlanRequest $request, Company $company, FeePricing $pricing): JsonResponse
    {
        $company->update($request->validated());
        $changes = Audit::changes($company);

        if (isset($changes['pricing_plan'])) {
            Audit::record('billing.pricing_plan.changed', $company, [
                'changes' => ['pricing_plan' => $changes['pricing_plan']],
            ], company: $company);
            unset($changes['pricing_plan']);
        }

        if ($changes !== []) {
            Audit::record('billing.settings.changed', $company, ['changes' => $changes], company: $company);
        }

        return response()->json($this->payload($company, $pricing));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Company $company, FeePricing $pricing): array
    {
        [$periodStart, $periodEnd] = $company->billingPeriodContaining(today());

        return [
            'pricing_plan' => $company->pricing_plan->value,
            'billing_cycle_day' => $company->billing_cycle_day,
            'next_billing_number' => $company->next_billing_number,
            'current_period' => ['start' => $periodStart->toDateString(), 'end' => $periodEnd->toDateString()],
            'fees' => CompanyFeeResource::collection($pricing->forCompany($company)),
            'special_rates' => CompanyFeeRateResource::collection(
                $company->feeRates()->with('fee')->orderBy('fee_id')->orderByDesc('starts_on')->get()
            ),
            // Fees a special rate can be set for (assigning the rate also assigns the fee).
            'available_fees' => Fee::query()->orderBy('name')->get()->map(fn (Fee $fee) => [
                'id' => $fee->id,
                'name' => $fee->name,
                'amount' => (string) $fee->amount,
                'frequency_label' => $fee->frequencyLabel(),
                'is_active' => $fee->is_active,
            ]),
        ];
    }
}
