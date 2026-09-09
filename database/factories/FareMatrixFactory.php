<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\FareMatrix;
use App\Models\Route;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FareMatrix>
 */
class FareMatrixFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'route_id' => Route::factory(),
            'amount' => fake()->randomElement([15, 20, 30, 45, 60, 80]),
            'discounted_amount' => null,
            'status' => 'Active',
        ];
    }
}
