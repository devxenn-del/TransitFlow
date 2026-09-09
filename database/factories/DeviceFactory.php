<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = Company::factory();

        return [
            'company_id' => $company,
            'user_id' => User::factory()->state(['company_id' => $company]),
            'device_uuid' => fake()->uuid(),
            'platform' => 'android',
            'model' => fake()->randomElement(['Samsung Galaxy A15', 'Xiaomi Redmi 12', 'Infinix Hot 40']),
            'app_version' => fake()->randomElement(['1.0.0', '1.1.0', '1.2.3']),
            'registered_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    public function offline(): static
    {
        return $this->state(fn () => ['last_seen_at' => now()->subHours(2)]);
    }
}
