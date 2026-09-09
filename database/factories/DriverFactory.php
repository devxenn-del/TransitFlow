<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Driver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Driver>
 */
class DriverFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => strtoupper(fake()->name()),
            'license_number' => strtoupper(fake()->bothify('??##-##-######')),
            'contact_number' => fake()->numerify('09#########'),
            'status' => 'Active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'Inactive']);
    }
}
