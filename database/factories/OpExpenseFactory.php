<?php

namespace Database\Factories;

use App\Models\Bus;
use App\Models\Company;
use App\Models\OpExpense;
use App\Support\Denominations;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpExpense>
 */
class OpExpenseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = Company::factory();
        $q = ['q100' => 2, 'q50' => 1, 'q20' => 0, 'q10' => 0, 'q5' => 0, 'q1' => 0, 'q1000' => 0, 'q500' => 0, 'q200' => 0];
        $amount = Denominations::total($q);

        return [
            'company_id' => $company,
            'bus_id' => Bus::factory()->state(['company_id' => $company]),
            'op_date' => now()->toDateString(),
            'shift' => 'Morning',
            'category' => 'Fuel',
            'description' => fake()->sentence(3),
            'amount' => $amount,
            ...$q,
            'status' => 'Active',
            'recorded_by_name' => fake()->name(),
            'recorded_at' => now(),
        ];
    }

    public function voided(): static
    {
        return $this->state(fn () => ['status' => 'Voided', 'voided_at' => now(), 'void_reason' => 'Wrong bus']);
    }
}
