<?php

namespace Tests\Feature\Operations;

use App\Models\Attendance;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Franchise;
use App\Models\PassengerType;
use App\Models\Route;
use App\Models\Terminal;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class TripLifecycleTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private User $conductor;

    private Bus $bus;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();

        $this->conductor = User::factory()->forCompany($this->company)->withRole('conductor')->create();
        Attendance::factory()->create(['company_id' => $this->company->id, 'user_id' => $this->conductor->id]);

        $this->bus = Bus::factory()->for($this->company)->create();
        $this->conductor->buses()->attach($this->bus);
        $this->driver = Driver::factory()->for($this->company)->create();

        Terminal::factory()->for($this->company)->create(['name' => 'MAIN', 'boarding_mode' => 'Both']);
        Terminal::factory()->for($this->company)->pickupOnly()->create(['name' => 'GARAGE']);

        // A priced coverage route MAIN → HUB.
        Franchise::factory()->for($this->company)->withStops(['MAIN', 'HUB'])->create()
            ->routes()->create(['company_id' => $this->company->id, 'origin' => 'MAIN', 'destination' => 'HUB', 'name' => 'MAIN - HUB', 'status' => 'Active'])
            ->fareMatrix()->create(['company_id' => $this->company->id, 'amount' => 25, 'status' => 'Active']);

        Sanctum::actingAs($this->conductor);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function startPayload(array $overrides = []): array
    {
        return array_merge([
            'bus_id' => $this->bus->id,
            'driver_id' => $this->driver->id,
            'origin' => 'MAIN',
            'coverage_origin' => 'MAIN',
            'coverage_destination' => 'HUB',
        ], $overrides);
    }

    public function test_a_conductor_starts_a_trip_at_departure(): void
    {
        $this->postJson('/api/conductor/trips', $this->startPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'Departure')
            ->assertJsonPath('data.bus_number', $this->bus->bus_number);

        $this->assertDatabaseHas('trips', ['conductor_id' => $this->conductor->id, 'company_id' => $this->company->id]);
    }

    public function test_a_trip_gets_a_reference_in_the_t_mmddy_y_bu_s_xxx_x_xxx_x_format(): void
    {
        $reference = $this->postJson('/api/conductor/trips', $this->startPayload())
            ->assertCreated()
            ->json('data.reference');

        $bus = preg_replace('/[^A-Z0-9]/', '', strtoupper($this->bus->bus_number));
        $this->assertMatchesRegularExpression(
            '/^T-'.now()->format('mdy').'-'.preg_quote($bus, '/').'-[A-Z0-9]{4}-[A-Z0-9]{4}$/',
            $reference,
        );
    }

    public function test_an_ended_trip_appears_on_the_remittance_desk_for_receiving(): void
    {
        $this->postJson('/api/conductor/trips', $this->startPayload())->assertCreated();
        $trip = Trip::query()->where('conductor_id', $this->conductor->id)->firstOrFail();
        $trip->tickets()->create(['company_id' => $this->company->id, 'passenger_type_id' => PassengerType::factory()->for($this->company)->create()->id, 'fare' => 25, 'issued_at' => now()]);

        $this->postJson('/api/conductor/trips/end')->assertOk()->assertJsonPath('data.status', 'Arrived');

        // A desk user sees it in the "to receive" queue by its reference.
        $office = User::factory()->forCompany($this->company)->withRole('office')->create();
        Sanctum::actingAs($office);

        $this->getJson('/api/company/remittances?stage=pending')
            ->assertOk()
            ->assertJsonPath('data.0.reference', $trip->reference)
            ->assertJsonPath('data.0.remittance.stage', 'pending');
    }

    public function test_starting_from_a_pickup_only_terminal_skips_departure(): void
    {
        // Price a GARAGE → HUB route so coverage validates.
        Route::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'origin' => 'GARAGE', 'destination' => 'HUB', 'name' => 'g', 'status' => 'Active'])
            ->fareMatrix()->create(['company_id' => $this->company->id, 'amount' => 30, 'status' => 'Active']);

        $this->postJson('/api/conductor/trips', $this->startPayload(['origin' => 'GARAGE', 'coverage_origin' => 'GARAGE']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'OnTrip');
    }

    public function test_cannot_start_a_second_live_trip(): void
    {
        $this->postJson('/api/conductor/trips', $this->startPayload())->assertCreated();
        $this->postJson('/api/conductor/trips', $this->startPayload())->assertJsonValidationErrorFor('trip');
    }

    public function test_cannot_start_with_an_unassigned_bus(): void
    {
        $other = Bus::factory()->for($this->company)->create();
        $this->postJson('/api/conductor/trips', $this->startPayload(['bus_id' => $other->id]))
            ->assertJsonValidationErrorFor('bus_id');
    }

    public function test_cannot_start_when_the_bus_is_already_out(): void
    {
        $mate = User::factory()->forCompany($this->company)->create();
        Trip::factory()->for($this->company)->create(['conductor_id' => $mate->id, 'bus_id' => $this->bus->id, 'status' => 'OnTrip']);

        $this->postJson('/api/conductor/trips', $this->startPayload())->assertJsonValidationErrorFor('bus_id');
    }

    public function test_cannot_start_with_an_unpriced_coverage_route(): void
    {
        $this->postJson('/api/conductor/trips', $this->startPayload(['coverage_destination' => 'NOWHERE']))
            ->assertJsonValidationErrorFor('coverage_destination');
    }

    public function test_mark_on_trip_then_end_with_default_remittance(): void
    {
        $this->postJson('/api/conductor/trips', $this->startPayload())->assertCreated();

        $this->postJson('/api/conductor/trips/mark-on-trip')->assertOk()->assertJsonPath('data.status', 'OnTrip');

        $trip = Trip::query()->where('conductor_id', $this->conductor->id)->firstOrFail();
        $trip->tickets()->create(['company_id' => $this->company->id, 'passenger_type_id' => PassengerType::factory()->for($this->company)->create()->id, 'fare' => 25, 'issued_at' => now()]);

        $this->postJson('/api/conductor/trips/end')
            ->assertOk()
            ->assertJsonPath('data.status', 'Arrived')
            ->assertJsonPath('data.summary.remitted_amount', 25)
            ->assertJsonPath('data.summary.balance', 0);
    }

    public function test_end_with_a_short_remittance_shows_the_balance(): void
    {
        $this->postJson('/api/conductor/trips', $this->startPayload())->assertCreated();
        $trip = Trip::query()->where('conductor_id', $this->conductor->id)->firstOrFail();
        $trip->tickets()->create(['company_id' => $this->company->id, 'passenger_type_id' => PassengerType::factory()->for($this->company)->create()->id, 'fare' => 100, 'issued_at' => now()]);

        $this->postJson('/api/conductor/trips/end', ['remitted_amount' => 90])
            ->assertOk()
            ->assertJsonPath('data.summary.balance', 10);
    }

    public function test_cancel_records_a_reason(): void
    {
        $this->postJson('/api/conductor/trips', $this->startPayload())->assertCreated();

        $this->postJson('/api/conductor/trips/cancel', ['reason' => 'Bus breakdown'])
            ->assertOk()
            ->assertJsonPath('data.status', 'Cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Bus breakdown');
    }

    public function test_another_conductor_cannot_see_or_touch_this_trip(): void
    {
        $this->postJson('/api/conductor/trips', $this->startPayload())->assertCreated();
        $tripId = Trip::query()->value('id');

        $mate = User::factory()->forCompany($this->company)->withRole('conductor')->create();
        Sanctum::actingAs($mate);

        $this->getJson("/api/conductor/trips/{$tripId}")->assertForbidden();
        $this->postJson('/api/conductor/trips/end')->assertStatus(409);
    }

    public function test_a_non_conductor_cannot_start_a_trip(): void
    {
        Sanctum::actingAs(User::factory()->forCompany($this->company)->create()); // office
        $this->postJson('/api/conductor/trips', $this->startPayload())->assertForbidden();
    }

    public function test_cannot_start_a_trip_without_clocking_in(): void
    {
        $freshConductor = User::factory()->forCompany($this->company)->withRole('conductor')->create();
        $freshConductor->buses()->attach($this->bus);
        Sanctum::actingAs($freshConductor);

        $this->postJson('/api/conductor/trips', $this->startPayload())
            ->assertJsonValidationErrorFor('attendance');
    }

}
