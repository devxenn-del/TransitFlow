<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyFeeRate;
use App\Models\Fee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyFeeRate>
 */
class CompanyFeeRateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'fee_id' => Fee::factory(),
            'amount' => 3500,
            'starts_on' => today()->startOfMonth()->subMonths(6),
            'ends_on' => null,
            'is_active' => true,
            'internal_notes' => fake()->sentence(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'starts_on' => today()->subYear(),
            'ends_on' => today()->subMonth(),
        ]);
    }
}
