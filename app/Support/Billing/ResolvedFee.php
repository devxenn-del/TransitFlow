<?php

namespace App\Support\Billing;

use App\Enums\PricingPlan;
use App\Models\CompanyFeeRate;
use App\Models\Fee;

/**
 * A fee as it applies to one company on one date: the amount to charge and
 * whether that is the standard rate or the company's special rate.
 * Produced by {@see FeePricing}.
 */
final readonly class ResolvedFee
{
    public function __construct(
        public Fee $fee,
        public string $amount,
        public PricingPlan $pricingType,
        public ?CompanyFeeRate $specialRate = null,
    ) {}

    public function isSpecial(): bool
    {
        return $this->pricingType === PricingPlan::Special;
    }
}
