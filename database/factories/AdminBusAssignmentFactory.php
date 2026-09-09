<?php

namespace Database\Factories;

use App\Models\AdminBusAssignment;
use App\Models\Bus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminBusAssignment>
 */
class AdminBusAssignmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = Company::factory();

        return [
            'company_id' => $company,
            'bus_id' => Bus::factory()->state(['company_id' => $company]),
            'user_id' => User::factory()->state(['company_id' => $company]),
            'shift' => 'Morning',
            'effective_from' => now()->toDateString(),
            'status' => 'Active',
        ];
    }
}
