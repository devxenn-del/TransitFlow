<?php

namespace Database\Factories;

use App\Models\Bus;
use App\Models\CharterBooking;
use App\Models\Company;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Callers normally pass bus_id / driver_id / conductor_id from the same
 * company (see tests/Feature/Charters).
 *
 * @extends Factory<CharterBooking>
 */
class CharterBookingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $departure = now()->addDays(3)->setTime(8, 0);

        return [
            'company_id' => Company::factory(),
            'client_name' => fake()->company(),
            'contact_person' => fake()->name(),
            'contact_number' => fake()->numerify('09#########'),
            'purpose' => 'Company outing',
            'pickup_point' => 'Main Office',
            'destination' => 'Tagaytay',
            'departure_at' => $departure,
            'return_at' => (clone $departure)->addHours(10),
            'agreed_price' => 15000,
            'bus_id' => Bus::factory(),
            'driver_id' => Driver::factory(),
            'conductor_id' => User::factory(),
            'status' => 'Pending',
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => 'Approved', 'approved_at' => now()]);
    }
}
