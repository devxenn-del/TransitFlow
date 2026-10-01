<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyDocument>
 */
class CompanyDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->words(3, true),
            'category' => fake()->randomElement(array_keys(CompanyDocument::CATEGORIES)),
            'description' => fake()->sentence(),
            'file_path' => 'company-documents/'.fake()->uuid().'.pdf',
            'original_name' => 'document.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDays(5)->toDateString()]);
    }
}
