<?php

namespace Database\Factories;

use App\Models\Bus;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bus>
 */
class BusFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'bus_number' => fake()->unique()->numerify('BUS-###'),
            'plate_number' => strtoupper(fake()->bothify('???-####')),
            'status' => 'Active',
        ];
    }
}
