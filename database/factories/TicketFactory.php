<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PassengerType;
use App\Models\Ticket;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
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
            'route_id' => null,
            'passenger_type_id' => PassengerType::factory()->state(['company_id' => $company]),
            'boarding_type' => 'Terminal',
            'payment_method' => 'Cash',
            'fare' => fake()->randomElement([13, 15, 20, 45]),
            'issued_at' => now(),
        ];
    }
}
