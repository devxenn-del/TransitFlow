<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_name' => fake()->name(),
            'action' => 'company.settings.updated',
            'context' => ['changed' => ['ticket_footer']],
            'created_at' => now(),
        ];
    }
}
