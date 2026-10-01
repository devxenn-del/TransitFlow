<?php

namespace App\Enums;

/**
 * Which pricing a company is billed under — chosen by the Super Admin only.
 * Under `Special`, the company's active special rates (company_fee_rates)
 * override the standard fee amounts; under `Standard` they are ignored.
 * Also used to tag a billed line as charged at the standard or special rate.
 */
enum PricingPlan: string
{
    case Standard = 'standard';
    case Special = 'special';

    public function rateLabel(): string
    {
        return match ($this) {
            self::Standard => 'Standard Rate',
            self::Special => 'Special Company Rate',
        };
    }
}
