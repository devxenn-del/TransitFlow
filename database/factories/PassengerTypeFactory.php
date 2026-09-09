<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PassengerType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PassengerType>
 */
class PassengerTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->unique()->word(),
            'fare_mode' => 'Fare Matrix',
            'discount_percent' => 0,
            'sort_order' => 10,
            'status' => 'Active',
        ];
    }

    public function discounted(float $percent = 20): static
    {
        return $this->state(fn () => ['discount_percent' => $percent]);
    }

    public function manualAmount(): static
    {
        return $this->state(fn () => ['fare_mode' => 'Manual Amount']);
    }
}
