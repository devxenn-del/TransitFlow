<?php

namespace App\Support;

/**
 * The BITS fare formula, ported verbatim from
 * api/tickets/issue.php::computeFare() — see docs/MIGRATION_MAP.md §4.1.
 *
 *   base            = fare_matrix.amount
 *   discountPercent = passenger_types.discount_percent
 *
 *   if discountPercent > 0 AND fare_matrix.discounted_amount IS NOT NULL:
 *       fare = discounted_amount            # manual override, used as-is
 *   else:
 *       fare = round(base * (1 - discountPercent/100))   # half away from zero, whole peso
 *
 * A Regular passenger (0%) never touches discounted_amount.
 */
class FareCalculator
{
    public static function compute(float $amount, ?float $discountedAmount, float $discountPercent): float
    {
        if ($discountPercent > 0 && $discountedAmount !== null) {
            return round($discountedAmount, 2);
        }

        return round($amount * (1 - ($discountPercent / 100)));
    }
}
