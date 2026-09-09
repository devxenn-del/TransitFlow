<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\RouteStop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Franchise>
 */
class FranchiseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $origin = strtoupper(fake()->city());
        $destination = strtoupper(fake()->city());

        return [
            'company_id' => Company::factory(),
            'applicant_name' => fake()->name().' Transport Coop.',
            'route_description' => "{$origin} - {$destination} VIA MAIN ROAD AND VICE VERSA",
            'route_origin' => $origin,
            'route_destination' => $destination,
            'case_no' => 'CASE '.fake()->numerify('####-####'),
            'status' => 'Active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'Inactive']);
    }

    /**
     * Attach an ordered stop list to the franchise.
     *
     * @param  list<string>  $names
     */
    public function withStops(array $names): static
    {
        return $this->afterCreating(function (Franchise $franchise) use ($names): void {
            foreach ($names as $i => $name) {
                RouteStop::withoutGlobalScopes()->create([
                    'company_id' => $franchise->company_id,
                    'franchise_id' => $franchise->id,
                    'name' => $name,
                    'sort_order' => $i + 1,
                    'status' => 'Active',
                ]);
            }
        });
    }
}
