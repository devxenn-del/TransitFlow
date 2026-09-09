<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\DataOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataOperation>
 */
class DataOperationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'type' => 'export',
            'performed_by_name' => fake()->name(),
            'summary' => ['trips' => 0],
            'created_at' => now(),
        ];
    }
}
