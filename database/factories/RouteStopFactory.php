<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\RouteStop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RouteStop>
 */
class RouteStopFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'franchise_id' => Franchise::factory(),
            'name' => strtoupper(fake()->unique()->streetName()),
            'sort_order' => fake()->numberBetween(1, 20),
            'status' => 'Active',
        ];
    }
}
