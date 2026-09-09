<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PassengerType;
use App\Models\PassengerTypeArticle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PassengerTypeArticle>
 */
class PassengerTypeArticleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'passenger_type_id' => PassengerType::factory()->manualAmount(),
            'label' => ucfirst(fake()->unique()->word()),
            'amount' => fake()->randomElement([5, 10, 15, 20]),
            'sort_order' => 10,
            'status' => 'Active',
        ];
    }
}
