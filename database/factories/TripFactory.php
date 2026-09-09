<?php

namespace Database\Factories;

use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trip>
 */
class TripFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = Company::factory();

        return [
            'company_id' => $company,
            'conductor_id' => User::factory()->state(['company_id' => $company]),
            'bus_id' => Bus::factory()->state(['company_id' => $company]),
            'driver_id' => Driver::factory()->state(['company_id' => $company]),
            'bus_number' => fake()->numerify('BUS-###'),
            'origin' => 'SM PALA-PALA',
            'coverage_origin' => 'SM PALA-PALA',
            'coverage_destination' => 'EPZA (ROSARIO)',
            'op_date' => now()->toDateString(),
            'shift' => 'Morning',
            'status' => 'Departure',
            'started_at' => now(),
        ];
    }

    public function onTrip(): static
    {
        return $this->state(fn () => ['status' => 'OnTrip', 'marked_on_trip_at' => now()]);
    }

    public function arrived(): static
    {
        return $this->state(fn () => ['status' => 'Arrived', 'ended_at' => now()]);
    }
}
