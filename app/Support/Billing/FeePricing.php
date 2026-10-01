<?php

namespace App\Support\Billing;

use App\Enums\PricingPlan;
use App\Models\Company;
use App\Models\CompanyFeeRate;
use App\Models\Fee;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The single source of truth for what a company is charged per fee.
 *
 * Precedence, for a company on a date:
 *   1. Only ACTIVE fees that apply to the company (every company, or
 *      explicitly assigned) are considered — disabled fees are never billed.
 *   2. If the company is on the Special pricing plan and has an active
 *      special rate for the fee whose window covers the date, that rate is
 *      charged (latest-starting one wins if several overlap).
 *   3. Otherwise — Standard plan, no special rate, or the special rate has
 *      expired / not started / been switched off — the fee's standard amount.
 *
 * Special rates are per company; resolving one company never reads another
 * company's rates, and nothing here writes to the fee's standard amount.
 */
class FeePricing
{
    /**
     * @return Collection<int, ResolvedFee>
     */
    public function forCompany(Company $company, ?CarbonInterface $on = null): Collection
    {
        $on ??= today();

        $fees = Fee::query()->appliedTo($company)->orderBy('name')->get();

        $specialRates = $company->pricing_plan === PricingPlan::Special && $fees->isNotEmpty()
            ? $company->feeRates()
                ->whereIn('fee_id', $fees->modelKeys())
                ->inEffectOn($on)
                ->orderByDesc('starts_on')
                ->orderByDesc('id')
                ->get()
                ->unique('fee_id')
                ->keyBy('fee_id')
            : collect();

        return $fees->map(function (Fee $fee) use ($specialRates): ResolvedFee {
            /** @var CompanyFeeRate|null $rate */
            $rate = $specialRates->get($fee->id);

            return $rate
                ? new ResolvedFee($fee, (string) $rate->amount, PricingPlan::Special, $rate)
                : new ResolvedFee($fee, (string) $fee->amount, PricingPlan::Standard);
        })->values();
    }

    /**
     * The fees to bill a company for a billing period: each fee with a charge
     * date inside the period, priced ON that charge date — so a special rate
     * that starts mid-period (on or before the charge) is applied, and one
     * that ended before the charge is not. Same precedence as {@see forCompany()}.
     *
     * @return Collection<int, ResolvedFee>
     */
    public function dueForPeriod(Company $company, CarbonInterface $periodStart, CarbonInterface $periodEnd): Collection
    {
        $start = CarbonImmutable::parse($periodStart)->startOfDay();
        $end = CarbonImmutable::parse($periodEnd)->startOfDay();

        /** @var Collection<int, array{fee: Fee, chargedOn: CarbonImmutable}> $due */
        $due = Fee::query()->appliedTo($company)->orderBy('name')->get()
            ->map(fn (Fee $fee) => ['fee' => $fee, 'chargedOn' => $fee->chargeDateOnOrAfter($start)])
            ->filter(fn (array $row) => $row['chargedOn'] !== null && $row['chargedOn']->lte($end))
            ->values();

        // Every active special rate overlapping the period, newest first; the
        // one covering each fee's charge date is picked below.
        $rates = $company->pricing_plan === PricingPlan::Special && $due->isNotEmpty()
            ? $company->feeRates()
                ->whereIn('fee_id', $due->pluck('fee.id'))
                ->where('is_active', true)
                ->whereDate('starts_on', '<=', $end->toDateString())
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start->toDateString()))
                ->orderByDesc('starts_on')
                ->orderByDesc('id')
                ->get()
                ->groupBy('fee_id')
            : collect();

        return $due->map(function (array $row) use ($rates): ResolvedFee {
            ['fee' => $fee, 'chargedOn' => $chargedOn] = $row;

            /** @var CompanyFeeRate|null $rate */
            $rate = $rates->get($fee->id, collect())->first(fn (CompanyFeeRate $rate) => $rate->starts_on->lte($chargedOn)
                && ($rate->ends_on === null || $rate->ends_on->gte($chargedOn)));

            return $rate
                ? new ResolvedFee($fee, (string) $rate->amount, PricingPlan::Special, $rate)
                : new ResolvedFee($fee, (string) $fee->amount, PricingPlan::Standard);
        });
    }

    /**
     * Every company a fee is billed to, each with the price it pays under
     * the same precedence as {@see forCompany()} — for the Super Admin's
     * "who is assigned this fee" view. Two queries regardless of company count.
     *
     * @return Collection<int, array{company: Company, resolved: ResolvedFee}>
     */
    public function companiesForFee(Fee $fee, ?CarbonInterface $on = null): Collection
    {
        $on ??= today();

        $companies = $fee->applies_to_all_companies
            ? Company::query()->orderBy('name')->get()
            : $fee->assignedCompanies()->orderBy('name')->get();

        $specialRates = CompanyFeeRate::query()
            ->where('fee_id', $fee->id)
            ->whereIn('company_id', $companies->where('pricing_plan', PricingPlan::Special)->modelKeys())
            ->inEffectOn($on)
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get()
            ->unique('company_id')
            ->keyBy('company_id');

        return $companies->map(function (Company $company) use ($fee, $specialRates): array {
            /** @var CompanyFeeRate|null $rate */
            $rate = $specialRates->get($company->id);

            return [
                'company' => $company,
                'resolved' => $rate
                    ? new ResolvedFee($fee, (string) $rate->amount, PricingPlan::Special, $rate)
                    : new ResolvedFee($fee, (string) $fee->amount, PricingPlan::Standard),
            ];
        })->values();
    }
}
