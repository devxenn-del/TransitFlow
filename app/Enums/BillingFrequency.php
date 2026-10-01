<?php

namespace App\Enums;

/**
 * How often a platform fee is charged to a company. `Custom` charges every
 * `billing_interval_months` months (set per fee); `OneTime` is charged once,
 * in the month the fee takes effect.
 */
enum BillingFrequency: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnual = 'semi_annual';
    case Yearly = 'yearly';
    case OneTime = 'one_time';
    case Custom = 'custom';

    /**
     * Months between charges; null for a one-time fee. A custom fee takes
     * its interval from the fee itself.
     */
    public function intervalMonths(?int $customInterval = null): ?int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::SemiAnnual => 6,
            self::Yearly => 12,
            self::OneTime => null,
            self::Custom => $customInterval,
        };
    }

    public function label(?int $customInterval = null): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::SemiAnnual => 'Semi-annual',
            self::Yearly => 'Yearly',
            self::OneTime => 'One-time',
            self::Custom => $customInterval ? "Every {$customInterval} months" : 'Custom',
        };
    }
}
