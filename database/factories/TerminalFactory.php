<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Terminal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Terminal>
 */
class TerminalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => strtoupper(fake()->unique()->city()).' TERMINAL',
            'default_route_origin' => null,
            'boarding_mode' => 'Both',
            'status' => 'Active',
        ];
    }

    public function pickupOnly(): static
    {
        return $this->state(fn () => ['boarding_mode' => 'Pickup']);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'Inactive']);
    }
}
