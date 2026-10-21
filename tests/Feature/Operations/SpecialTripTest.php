<?php

namespace Tests\Feature\Operations;

use App\Models\Attendance;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Franchise;
use App\Models\PassengerType;
use App\Models\Terminal;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

/**
 * Start Trip by trip type (App\Actions\StartTrip / StartTripRequest):
 * Regular needs a route and origin/destination; Special (charter / hire)
 * needs only the bus and the driver verified at sign-in, and issues no
 * passenger tickets.
 */
class SpecialTripTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private User $conductor;

    private Bus $bus;

    private Driver $driver;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->conductor = User::factory()->forCompany($this->company)->withRole('conductor')->create(['email' => 'conductor@acme.test']);
        Attendance::factory()->create(['company_id' => $this->company->id, 'user_id' => $this->conductor->id]);

        $this->bus = Bus::factory()->for($this->company)->create();
        $this->conductor->buses()->attach($this->bus);

        Terminal::factory()->for($this->company)->create(['name' => 'MAIN', 'boarding_mode' => 'Both']);
        Franchise::factory()->for($this->company)->withStops(['MAIN', 'HUB'])->create()
            ->routes()->create(['company_id' => $this->company->id, 'origin' => 'MAIN', 'destination' => 'HUB', 'name' => 'MAIN - HUB', 'status' => 'Active'])
            ->fareMatrix()->create(['company_id' => $this->company->id, 'amount' => 25, 'status' => 'Active']);

        $this->driver = Driver::factory()->for($this->company)->create();
        $this->token = $this->postJson('/api/auth/login', [
            'company_code' => $this->company->code, 'email' => 'conductor@acme.test', 'password' => 'password', 'driver_code' => $this->driver->driver_code,
        ])->assertOk()->json('token');
    }

    public function test_a_special_trip_starts_with_only_the_bus_and_driver(): void
    {
        $res = $this->withToken($this->token)
            ->postJson('/api/conductor/trips', [
                'bus_id' => $this->bus->id,
                'driver_id' => $this->driver->id,
                'trip_type' => 'Special',
            ])
            ->assertCreated();

        $trip = Trip::query()->findOrFail($res->json('data.id'));
        $this->assertSame('Special', $trip->trip_type);
        $this->assertNull($trip->coverage_origin);
        $this->assertNull($trip->coverage_destination);
        $this->assertNull($trip->franchise_id);
        // No terminal boarding phase for a charter.
        $this->assertSame('OnTrip', $trip->status);
    }

    public function test_a_regular_trip_still_requires_its_origin_and_destination(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/conductor/trips', [
                'bus_id' => $this->bus->id,
                'driver_id' => $this->driver->id,
                'trip_type' => 'Regular',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['origin', 'coverage_origin', 'coverage_destination']);
    }

    public function test_a_special_trip_does_not_issue_passenger_tickets(): void
    {
        $tripId = $this->withToken($this->token)
            ->postJson('/api/conductor/trips', ['bus_id' => $this->bus->id, 'driver_id' => $this->driver->id, 'trip_type' => 'Special'])
            ->assertCreated()
            ->json('data.id');
        $type = PassengerType::factory()->for($this->company)->create();

        $this->withToken($this->token)
            ->postJson('/api/conductor/tickets', [
                'trip_id' => $tripId,
                'passenger_type_id' => $type->id,
                'manual_amount' => 50,
                'boarding_type' => 'Pickup',
                'payment_method' => 'Cash',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('trip');
    }

    public function test_a_special_trips_remittance_report_is_labelled_special_instead_of_a_route(): void
    {
        $tripId = $this->withToken($this->token)
            ->postJson('/api/conductor/trips', ['bus_id' => $this->bus->id, 'driver_id' => $this->driver->id, 'trip_type' => 'Special'])
            ->assertCreated()
            ->json('data.id');

        $lines = collect($this->withToken($this->token)
            ->getJson("/api/conductor/trips/{$tripId}/receipt/remittance")
            ->assertOk()
            ->json('data.lines'));

        $this->assertSame('SPECIAL', $lines->firstWhere('label', 'Trip Type')['value']);
        $this->assertNull($lines->first(fn ($l) => str_starts_with($l['text'] ?? '', 'Coverage:')));
    }
}
