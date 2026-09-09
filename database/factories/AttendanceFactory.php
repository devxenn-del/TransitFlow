<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
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
            'clock_in_at' => now()->subHours(2),
            'clock_out_at' => null,
            'clock_in_source' => 'web',
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'clock_in_at' => now()->subHours(9),
            'clock_out_at' => now()->subHour(),
            'clock_out_source' => 'web',
        ]);
    }

    public function forcedClosed(): static
    {
        return $this->closed()->state(fn () => [
            'closed_by' => User::factory(),
            'closed_note' => 'Forgot to clock out',
        ]);
    }
}
