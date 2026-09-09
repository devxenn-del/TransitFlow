<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Dispatch;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dispatch>
 */
class DispatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = Company::factory();

        return [
            'company_id' => $company,
            'trip_id' => Trip::factory()->state(['company_id' => $company]),
            'barker_name' => fake()->name(),
            'amount' => fake()->randomElement([20, 30, 40, 50]),
            'dispatched_at' => now(),
        ];
    }

    public function forTrip(Trip $trip): static
    {
        return $this->state(fn () => [
            'company_id' => $trip->company_id,
            'trip_id' => $trip->id,
        ]);
    }
}
