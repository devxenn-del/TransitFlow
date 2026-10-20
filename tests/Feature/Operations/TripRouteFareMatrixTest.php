<?php

namespace Tests\Feature\Operations;

use App\Actions\SaveFareCell;
use App\Models\Attendance;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Franchise;
use App\Models\Terminal;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

/**
 * The route picked at Start Trip drives the conductor app's ticketing: the
 * trip remembers its franchise, and gets that franchise's full stop list and
 * only its fare matrix.
 */
class TripRouteFareMatrixTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private User $conductor;

    private Bus $bus;

    private Driver $driver;

    private Franchise $longRoute;

    private Franchise $shortRoute;

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

        // Two routes sharing the stop names MAIN and HUB, priced differently.
        $save = app(SaveFareCell::class);
        $this->longRoute = Franchise::factory()->for($this->company)->withStops(['MAIN', 'S2', 'HUB', 'S4', 'END'])->create();
        foreach (['S2' => 10, 'HUB' => 20, 'S4' => 30, 'END' => 40] as $to => $amount) {
            $save->handle($this->longRoute, 'MAIN', $to, $amount);
        }
        $save->handle($this->longRoute, 'HUB', 'END', 15);

        $this->shortRoute = Franchise::factory()->for($this->company)->withStops(['MAIN', 'HUB'])->create();
        $save->handle($this->shortRoute, 'MAIN', 'HUB', 99);

        Sanctum::actingAs($this->conductor);
    }

    private function start(array $overrides = []): Trip
    {
        $id = $this->postJson('/api/conductor/trips', array_merge([
            'bus_id' => $this->bus->id,
            'driver_id' => $this->driver->id,
            'franchise_id' => $this->longRoute->id,
            'origin' => 'MAIN',
            'coverage_origin' => 'MAIN',
            'coverage_destination' => 'HUB',
        ], $overrides))->assertCreated()->json('data.id');

        return Trip::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_the_trip_remembers_the_route_picked_at_start_trip(): void
    {
        $trip = $this->start();

        $this->assertSame($this->longRoute->id, $trip->franchise_id);
    }

    public function test_stops_are_the_whole_route_not_just_the_declared_coverage(): void
    {
        $trip = $this->start(); // coverage MAIN → HUB is only stops 1–3 of 5

        $this->getJson("/api/conductor/trips/{$trip->id}/stops")
            ->assertOk()
            ->assertJsonPath('origin_index', 0)
            ->assertJsonPath('destination_index', 2)
            ->assertJsonPath('direction', 'forward')
            ->assertJsonCount(5, 'stops')
            ->assertJsonPath('stops.4.name', 'END');
    }

    public function test_fares_come_only_from_the_trips_own_route(): void
    {
        $trip = $this->start();

        $fares = $this->getJson("/api/conductor/trips/{$trip->id}/fares")->assertOk();

        $terminal = collect($fares->json('terminal'));
        $this->assertSame(['S2', 'HUB', 'S4', 'END'], $terminal->pluck('destination')->all());
        $this->assertEquals(20, $terminal->firstWhere('destination', 'HUB')['amount']); // not the other route's ₱99

        $this->assertNotNull(collect($fares->json('pickup'))->first(fn ($f) => $f['origin'] === 'HUB' && $f['destination'] === 'END'));
        $this->assertNull(collect($fares->json('pickup'))->first(fn ($f) => $f['amount'] == 99));
    }

    public function test_the_coverage_must_be_priced_on_the_picked_route(): void
    {
        $this->postJson('/api/conductor/trips', [
            'bus_id' => $this->bus->id,
            'driver_id' => $this->driver->id,
            'franchise_id' => $this->shortRoute->id,
            'origin' => 'MAIN',
            'coverage_origin' => 'MAIN',
            'coverage_destination' => 'END', // only priced on the long route
        ])->assertJsonValidationErrorFor('coverage_destination');
    }

    public function test_another_companys_route_is_rejected(): void
    {
        $other = Franchise::factory()->for(Company::factory())->withStops(['MAIN', 'HUB'])->create();

        $this->postJson('/api/conductor/trips', [
            'bus_id' => $this->bus->id,
            'driver_id' => $this->driver->id,
            'franchise_id' => $other->id,
            'origin' => 'MAIN',
            'coverage_origin' => 'MAIN',
            'coverage_destination' => 'HUB',
        ])->assertJsonValidationErrorFor('franchise_id');
    }

    public function test_a_trip_from_an_older_app_still_gets_its_whole_route(): void
    {
        $trip = $this->start(['franchise_id' => null, 'coverage_destination' => 'END']);
        $this->assertNull($trip->franchise_id);

        $this->getJson("/api/conductor/trips/{$trip->id}/stops")
            ->assertOk()
            ->assertJsonCount(5, 'stops');
    }
}
