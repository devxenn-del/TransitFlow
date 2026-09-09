<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\FareMatrix;
use App\Models\Route;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Route>
 */
class RouteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $origin = strtoupper(fake()->unique()->streetName());
        $destination = strtoupper(fake()->unique()->streetName());

        return [
            'company_id' => Company::factory(),
            'name' => "{$origin} - {$destination}",
            'origin' => $origin,
            'destination' => $destination,
            'status' => 'Active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'Inactive']);
    }

    /**
     * Give the route an Active fare (so it's "priced" / usable).
     */
    public function priced(float $amount = 45, ?float $discounted = null): static
    {
        return $this->afterCreating(function (Route $route) use ($amount, $discounted): void {
            FareMatrix::withoutGlobalScopes()->create([
                'company_id' => $route->company_id,
                'route_id' => $route->id,
                'amount' => $amount,
                'discounted_amount' => $discounted,
                'status' => 'Active',
            ]);
        });
    }
}
