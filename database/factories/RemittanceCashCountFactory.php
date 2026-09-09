<?php

namespace Database\Factories;

use App\Models\Bus;
use App\Models\Company;
use App\Models\RemittanceCashCount;
use App\Models\Trip;
use App\Support\Denominations;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RemittanceCashCount>
 */
class RemittanceCashCountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = Company::factory();
        $q = ['q1000' => 0, 'q500' => 1, 'q200' => 0, 'q100' => 3, 'q50' => 2, 'q20' => 4, 'q10' => 1, 'q5' => 0, 'q1' => 0];
        $counted = Denominations::total($q);

        return [
            'company_id' => $company,
            'trip_id' => Trip::factory()->state(['company_id' => $company]),
            'bus_id' => Bus::factory()->state(['company_id' => $company]),
            'op_date' => now()->toDateString(),
            'shift' => 'Morning',
            ...$q,
            'counted_total' => $counted,
            'expected_amount' => $counted,
            'variance' => 0,
            'status' => 'Received',
            'received_by_name' => fake()->name(),
            'received_at' => now(),
        ];
    }

    public function voided(): static
    {
        return $this->state(fn () => ['status' => 'Voided', 'voided_at' => now(), 'void_reason' => 'Miscount']);
    }
}
