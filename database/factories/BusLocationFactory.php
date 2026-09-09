<?php

namespace Database\Factories;

use App\Models\Bus;
use App\Models\BusLocation;
use App\Models\Company;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusLocation>
 */
class BusLocationFactory extends Factory
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
            'bus_id' => Bus::factory()->state(['company_id' => $company]),
            // Roughly the Cavite service area.
            'lat' => fake()->randomFloat(7, 14.20, 14.45),
            'lng' => fake()->randomFloat(7, 120.85, 121.05),
            'speed_kph' => fake()->randomFloat(1, 0, 60),
            'heading' => fake()->numberBetween(0, 359),
            'recorded_at' => now(),
        ];
    }

    public function forTrip(Trip $trip): static
    {
        return $this->state(fn () => [
            'company_id' => $trip->company_id,
            'trip_id' => $trip->id,
            'bus_id' => $trip->bus_id,
        ]);
    }

    public function recordedAt(\DateTimeInterface|string $when): static
    {
        return $this->state(fn () => ['recorded_at' => $when]);
    }
}
