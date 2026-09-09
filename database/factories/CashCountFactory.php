<?php

namespace Database\Factories;

use App\Models\Bus;
use App\Models\CashCount;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashCount>
 *
 * The rollup is normally maintained by RollUpBusDayCashCount; this factory
 * is only for tests that need a standalone row.
 */
class CashCountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = Company::factory();

        return [
            'company_id' => $company,
            'bus_id' => Bus::factory()->state(['company_id' => $company]),
            'op_date' => now()->toDateString(),
            'shift' => 'Morning',
            'remitted_total' => 0,
            'counted_total' => 0,
            'trip_count' => 0,
            'variance' => 0,
        ];
    }

    public function shift(string $shift): static
    {
        return $this->state(fn () => ['shift' => $shift]);
    }
}
