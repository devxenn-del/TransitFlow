<?php

namespace Tests\Feature\Operations;

use App\Models\Bus;
use App\Models\BusLocation;
use App\Models\Company;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class LiveMonitoringTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private Bus $bus;

    private User $conductor;

    private User $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->bus = Bus::factory()->for($this->company)->create(['bus_number' => 'BUS-01']);
        $this->conductor = User::factory()->forCompany($this->company)->withRole('conductor')->create();
        $this->office = User::factory()->forCompany($this->company)->withRole('office')->create();
    }

    private function liveTrip(array $overrides = []): Trip
    {
        return Trip::factory()->for($this->company)->create([
            'conductor_id' => $this->conductor->id,
            'bus_id' => $this->bus->id,
            'bus_number' => $this->bus->bus_number,
            'status' => 'OnTrip',
            'op_date' => now()->toDateString(),
            ...$overrides,
        ]);
    }

    public function test_a_conductor_reports_a_gps_fix_for_the_bus_on_their_live_trip(): void
    {
        $trip = $this->liveTrip();

        Sanctum::actingAs($this->conductor);

        $this->postJson('/api/conductor/trips/location', [
            'bus_id' => $this->bus->id,
            'lat' => 14.309123,
            'lng' => 120.912345,
            'speed_kph' => 32.4,
            'heading' => 90,
        ])->assertCreated()->assertJsonPath('data.trip_id', $trip->id);

        $this->assertDatabaseHas('bus_locations', [
            'trip_id' => $trip->id,
            'bus_id' => $this->bus->id,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_a_fix_for_a_bus_that_is_not_on_the_live_trip_is_rejected(): void
    {
        $this->liveTrip();
        $otherBus = Bus::factory()->for($this->company)->create();

        Sanctum::actingAs($this->conductor);

        $this->postJson('/api/conductor/trips/location', [
            'bus_id' => $otherBus->id, 'lat' => 14.3, 'lng' => 120.9,
        ])->assertStatus(422);

        $this->assertDatabaseCount('bus_locations', 0);
    }

    public function test_a_fix_with_no_live_trip_is_rejected(): void
    {
        $this->liveTrip(['status' => 'Arrived']);

        Sanctum::actingAs($this->conductor);

        $this->postJson('/api/conductor/trips/location', [
            'bus_id' => $this->bus->id, 'lat' => 14.3, 'lng' => 120.9,
        ])->assertStatus(409);
    }

    public function test_a_future_or_very_stale_client_timestamp_is_replaced_with_now(): void
    {
        $this->liveTrip();
        Sanctum::actingAs($this->conductor);

        $this->postJson('/api/conductor/trips/location', [
            'bus_id' => $this->bus->id, 'lat' => 14.3, 'lng' => 120.9,
            'recorded_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated();

        $row = BusLocation::query()->latest('id')->first();
        $this->assertTrue($row->recorded_at->lessThanOrEqualTo(now()->addMinute()));
    }

    public function test_the_live_board_shows_live_trips_with_their_latest_fix(): void
    {
        $trip = $this->liveTrip();
        Ticket::factory()->for($this->company)->count(2)->create(['trip_id' => $trip->id, 'fare' => 15]);
        BusLocation::factory()->forTrip($trip)->recordedAt(now()->subMinutes(5))->create(['lat' => 1, 'lng' => 1]);
        BusLocation::factory()->forTrip($trip)->recordedAt(now()->subSeconds(10))->create(['lat' => 14.31, 'lng' => 120.90]);

        Sanctum::actingAs($this->office);

        $data = $this->getJson('/api/company/live')->assertOk()->json('data');

        $this->assertSame(1, $data['counters']['live']);
        $this->assertSame(1, $data['counters']['on_trip']);
        $this->assertSame(0, $data['counters']['stale']);
        $row = $data['live_trips'][0];
        $this->assertSame($trip->id, $row['trip_id']);
        $this->assertSame(2, $row['ticket_count']);
        $this->assertEquals(30, $row['collected']);
        $this->assertEqualsWithDelta(14.31, $row['location']['lat'], 0.001);
        $this->assertFalse($row['location']['is_stale']);
    }

    public function test_a_fix_older_than_two_minutes_is_flagged_stale(): void
    {
        $trip = $this->liveTrip();
        BusLocation::factory()->forTrip($trip)->recordedAt(now()->subMinutes(3))->create();

        Sanctum::actingAs($this->office);

        $data = $this->getJson('/api/company/live')->assertOk()->json('data');

        $this->assertTrue($data['live_trips'][0]['location']['is_stale']);
        $this->assertSame(1, $data['counters']['stale']);
    }

    public function test_a_live_trip_with_no_fix_yet_reports_null_location_and_counts_as_stale(): void
    {
        $this->liveTrip();

        Sanctum::actingAs($this->office);

        $data = $this->getJson('/api/company/live')->assertOk()->json('data');

        $this->assertNull($data['live_trips'][0]['location']);
        $this->assertSame(1, $data['counters']['stale']);
    }

    public function test_arrived_today_lists_trips_awaiting_remittance_with_their_stage(): void
    {
        $this->liveTrip([
            'status' => 'Arrived',
            'ended_at' => now()->subHour(),
            'remitted_amount' => 500,
            'remittance_received_at' => now(),
        ]);

        Sanctum::actingAs($this->office);

        $data = $this->getJson('/api/company/live')->assertOk()->json('data');

        $this->assertSame(1, $data['counters']['arrived_today']);
        $this->assertSame('received', $data['arrived_today'][0]['remittance_stage']);
    }

    public function test_the_live_board_is_company_scoped(): void
    {
        $this->liveTrip();

        $other = Company::factory()->create();
        $otherConductor = User::factory()->forCompany($other)->withRole('conductor')->create();
        Trip::factory()->for($other)->create([
            'conductor_id' => $otherConductor->id, 'status' => 'OnTrip', 'op_date' => now()->toDateString(),
        ]);

        Sanctum::actingAs($this->office);

        $data = $this->getJson('/api/company/live')->assertOk()->json('data');

        $this->assertSame(1, $data['counters']['live']);
    }

    public function test_the_live_board_needs_tracking_view_and_the_ping_needs_tracking_ping(): void
    {
        $this->liveTrip();

        // A conductor has tracking.ping but not tracking.view.
        Sanctum::actingAs($this->conductor);
        $this->getJson('/api/company/live')->assertForbidden();

        // Office has tracking.view but not tracking.ping.
        Sanctum::actingAs($this->office);
        $this->postJson('/api/conductor/trips/location', [
            'bus_id' => $this->bus->id, 'lat' => 14.3, 'lng' => 120.9,
        ])->assertForbidden();
    }
}
