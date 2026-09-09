<?php

namespace Tests\Unit;

use App\Support\FareCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The BITS fare formula (docs/MIGRATION_MAP.md §4.1). These cases are the
 * contract every ticket sale must honour when the Tickets module lands.
 */
class FareCalculatorTest extends TestCase
{
    public function test_regular_passenger_pays_the_base_fare(): void
    {
        $this->assertSame(45.0, FareCalculator::compute(45.00, null, 0));
        // A discounted_amount is ignored for a 0% passenger.
        $this->assertSame(45.0, FareCalculator::compute(45.00, 30.00, 0));
    }

    public function test_percentage_discount_is_rounded_to_whole_peso(): void
    {
        // 45 * (1 - 0.20) = 36
        $this->assertSame(36.0, FareCalculator::compute(45.00, null, 20));
        // 47 * 0.80 = 37.6 -> round -> 38
        $this->assertSame(38.0, FareCalculator::compute(47.00, null, 20));
        // 13 * 0.80 = 10.4 -> round -> 10
        $this->assertSame(10.0, FareCalculator::compute(13.00, null, 20));
    }

    public function test_manual_discounted_amount_overrides_the_percentage_when_the_passenger_is_discounted(): void
    {
        // discountPercent > 0 AND discounted_amount set -> use it verbatim.
        $this->assertSame(32.0, FareCalculator::compute(45.00, 32.00, 20));
    }

    public function test_discounted_amount_is_ignored_without_a_discount_percentage(): void
    {
        $this->assertSame(45.0, FareCalculator::compute(45.00, 32.00, 0));
    }
}
