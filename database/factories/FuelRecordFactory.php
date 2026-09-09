<?php

namespace Database\Factories;

use App\Models\Bus;
use App\Models\Company;
use App\Models\FuelRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FuelRecord>
 */
class FuelRecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = Company::factory();
        $liters = fake()->randomFloat(2, 20, 120);
        $ppl = fake()->randomFloat(2, 55, 70);

        return [
            'company_id' => $company,
            'bus_id' => Bus::factory()->state(['company_id' => $company]),
            'fuel_type' => 'Diesel',
            'liters' => $liters,
            'price_per_liter' => $ppl,
            'amount_paid' => round($liters * $ppl, 2),
            'odometer' => fake()->numberBetween(50_000, 400_000),
            'station' => fake()->company().' Station',
            'fueled_at' => now()->subHours(fake()->numberBetween(1, 72)),
            'recorded_by_name' => fake()->name(),
            'recorded_by_role' => 'Office',
        ];
    }
}
