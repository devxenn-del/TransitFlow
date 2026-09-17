<?php

namespace Tests\Feature\Operations;

use App\Models\Attendance;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Franchise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

/**
 * A conductor's token records which driver they verified at sign-in
 * (AuthController::tokenAbilitiesFor()) — every trip on that session must
 * use that same driver (App\Actions\StartTrip). Deliberately its own test
 * class, authenticating via a real login + bearer token rather than
 * Sanctum::actingAs() (which only stubs can() calls and can't express "has
 * this ability but not that one," so it can't exercise real token-ability
 * introspection) — see App\Models\Driver::verifiedIdForToken().
 */
class TripDriverLockTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private User $conductor;

    private Bus $bus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->conductor = User::factory()->forCompany($this->company)->withRole('conductor')->create(['email' => 'conductor@acme.test']);
        Attendance::factory()->create(['company_id' => $this->company->id, 'user_id' => $this->conductor->id]);

        $this->bus = Bus::factory()->for($this->company)->create();
        $this->conductor->buses()->attach($this->bus);

        \App\Models\Terminal::factory()->for($this->company)->create(['name' => 'MAIN', 'boarding_mode' => 'Both']);
        Franchise::factory()->for($this->company)->withStops(['MAIN', 'HUB'])->create()
            ->routes()->create(['company_id' => $this->company->id, 'origin' => 'MAIN', 'destination' => 'HUB', 'name' => 'MAIN - HUB', 'status' => 'Active'])
            ->fareMatrix()->create(['company_id' => $this->company->id, 'amount' => 25, 'status' => 'Active']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function startPayload(int $driverId, array $overrides = []): array
    {
        return array_merge([
            'bus_id' => $this->bus->id,
            'driver_id' => $driverId,
            'origin' => 'MAIN',
            'coverage_origin' => 'MAIN',
            'coverage_destination' => 'HUB',
        ], $overrides);
    }

    private function loginWithDriverCode(string $driverCode): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => 'conductor@acme.test', 'password' => 'password', 'driver_code' => $driverCode,
        ])->assertOk()->json('token');
    }

    public function test_a_trip_using_the_driver_verified_at_sign_in_succeeds(): void
    {
        $driver = Driver::factory()->for($this->company)->create();
        $token = $this->loginWithDriverCode($driver->driver_code);

        $this->withToken($token)
            ->postJson('/api/conductor/trips', $this->startPayload($driver->id))
            ->assertCreated();
    }

    public function test_starting_a_trip_with_a_different_driver_than_verified_at_sign_in_is_rejected(): void
    {
        $verified = Driver::factory()->for($this->company)->create();
        $other = Driver::factory()->for($this->company)->create();
        $token = $this->loginWithDriverCode($verified->driver_code);

        $this->withToken($token)
            ->postJson('/api/conductor/trips', $this->startPayload($other->id))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('driver_id');
    }
}
