<?php

namespace Database\Factories;

use App\Enums\BillingStatementStatus;
use App\Models\BillingStatement;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BillingStatement>
 */
class BillingStatementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStart = today()->startOfMonth();

        return [
            'company_id' => Company::factory(),
            'reference' => 'BILL-'.Str::upper(Str::random(8)),
            'billing_number' => fake()->unique()->numberBetween(1, 999999),
            'period_start' => $periodStart,
            'period_end' => $periodStart->copy()->endOfMonth()->startOfDay(),
            'issued_on' => $periodStart,
            'due_on' => $periodStart->copy()->addDays(15),
            'total' => 5000,
            'status' => BillingStatementStatus::Unpaid->value,
        ];
    }
}
