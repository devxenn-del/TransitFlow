<?php

namespace Database\Factories;

use App\Models\Bus;
use App\Models\Company;
use App\Models\EvChargingSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EvChargingSession>
 */
class EvChargingSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = Company::factory();
        $bus = Bus::factory()->state(['company_id' => $company]);

        return [
            'company_id' => $company,
            'bus_id' => $bus,
            'status' => 'Charging',
            'started_at' => now()->subHour(),
            'battery_start_pct' => fake()->numberBetween(10, 40),
            'location' => 'Depot bay '.fake()->numberBetween(1, 6),
            'started_by_name' => fake()->name(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (EvChargingSession $s): void {
            if ($s->status === 'Charging' && $s->active_bus_id === null) {
                $s->active_bus_id = $s->bus_id;
            }
        });
    }

    public function completed(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => 'Completed',
            'ended_at' => now(),
            'battery_end_pct' => min(100, ($attrs['battery_start_pct'] ?? 20) + 55),
            'ended_by_name' => fake()->name(),
            'active_bus_id' => null,
        ]);
    }
}
