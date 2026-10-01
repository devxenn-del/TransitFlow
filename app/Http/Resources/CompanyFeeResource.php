<?php

namespace App\Http\Resources;

use App\Support\Billing\ResolvedFee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A fee as it applies to one company (App\Support\Billing\ResolvedFee).
 * What a company user sees is limited to their own charge: the standard
 * amount, special-rate window and anything internal are added only for the
 * Super Admin.
 *
 * @property ResolvedFee $resource
 */
class CompanyFeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $fee = $this->resource->fee;
        $rate = $this->resource->specialRate;
        $effectiveDate = $rate?->starts_on->max($fee->effective_date) ?? $fee->effective_date;
        $nextBillingDate = $fee->nextBillingDate(today());

        return [
            'fee_id' => $fee->id,
            'name' => $fee->name,
            'description' => $fee->description,
            'amount' => $this->resource->amount,
            'billing_frequency' => $fee->billing_frequency->value,
            'frequency_label' => $fee->frequencyLabel(),
            'pricing_type' => $this->resource->pricingType->value,
            'rate_label' => $this->resource->pricingType->rateLabel(),
            'effective_date' => $effectiveDate->toDateString(),
            'status' => $fee->effective_date->isFuture() ? 'scheduled' : 'active',
            'next_billing_date' => $nextBillingDate?->toDateString(),
            $this->mergeWhen((bool) $request->user()?->isSuperAdmin(), fn () => [
                'standard_amount' => (string) $fee->amount,
                'special_rate_id' => $rate?->id,
                'special_rate_ends_on' => $rate?->ends_on?->toDateString(),
            ]),
        ];
    }
}
