<?php

namespace Database\Factories;

use App\Enums\BillingFrequency;
use App\Models\Company;
use App\Models\Fee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Fee>
 */
class FeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true).' Fee',
            'description' => fake()->sentence(),
            'amount' => fake()->randomElement([1000, 3000, 5000]),
            'billing_frequency' => BillingFrequency::Monthly->value,
            'billing_interval_months' => null,
            'applies_to_all_companies' => true,
            'is_active' => true,
            'effective_date' => today()->startOfMonth()->subYear(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function frequency(BillingFrequency $frequency, ?int $intervalMonths = null): static
    {
        return $this->state(fn () => [
            'billing_frequency' => $frequency->value,
            'billing_interval_months' => $intervalMonths,
        ]);
    }

    /**
     * Billed only to the given companies.
     */
    public function assignedTo(Company ...$companies): static
    {
        return $this->state(fn () => ['applies_to_all_companies' => false])
            ->afterCreating(fn (Fee $fee) => $fee->assignedCompanies()->attach(array_map(fn (Company $company) => $company->id, $companies)));
    }
}
