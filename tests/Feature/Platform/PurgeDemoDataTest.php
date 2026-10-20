<?php

namespace Tests\Feature\Platform;

use App\Actions\SaveFareCell;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Franchise;
use App\Models\PassengerType;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Terminal;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class PurgeDemoDataTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $perjoda;

    private Company $southline;

    protected function setUp(): void
    {
        parent::setUp();

        // What the old DemoCompanySeeder left on a live server…
        $this->perjoda = Company::factory()->create(['code' => 'PERJODA']);
        $this->southline = Company::factory()->create(['code' => 'SOUTHLINE']);
        User::factory()->forCompany($this->southline)->withRole('company_admin')->create(['email' => 'admin@southline.test']);

        User::factory()->forCompany($this->perjoda)->withRole('company_admin')->create(['email' => 'admin@perjoda.test']);
        User::factory()->forCompany($this->perjoda)->withRole('manager')->create(['email' => 'manager@perjoda.test']);
        User::factory()->forCompany($this->perjoda)->withRole('office')->create(['email' => 'staff@perjoda.test']);
        $conductor = User::factory()->forCompany($this->perjoda)->withRole('conductor')->create(['email' => 'conductor@perjoda.test']);

        $franchise = Franchise::factory()->for($this->perjoda)->withStops(['SM PALA-PALA', 'LANGKAAN', 'EPZA (ROSARIO)'])->create(['case_no' => 'CASE 2019-0417']);
        app(SaveFareCell::class)->handle($franchise, 'SM PALA-PALA', 'LANGKAAN', 13);

        $bus = Bus::factory()->create(['company_id' => $this->perjoda->id, 'bus_number' => 'PERJODA-003']);
        $driver = Driver::factory()->create(['company_id' => $this->perjoda->id, 'name' => 'JUAN DELA CRUZ']);
        Terminal::factory()->create(['company_id' => $this->perjoda->id, 'name' => 'SM PALA-PALA']);
        $regular = PassengerType::factory()->for($this->perjoda)->create(['name' => 'Regular']);

        $trip = Trip::factory()->create(['company_id' => $this->perjoda->id, 'conductor_id' => $conductor->id, 'bus_id' => $bus->id, 'driver_id' => $driver->id, 'status' => 'OnTrip']);
        Ticket::factory()->create(['company_id' => $this->perjoda->id, 'trip_id' => $trip->id, 'passenger_type_id' => $regular->id]);

        // …and the operator's own data, which must survive.
        Driver::factory()->create(['company_id' => $this->perjoda->id, 'name' => 'CHARLIE']);
        Bus::factory()->create(['company_id' => $this->perjoda->id, 'bus_number' => 'BUS-REAL-1']);
        Franchise::factory()->for($this->perjoda)->withStops(['A', 'B'])->create(['case_no' => 'CASE 2026-0001']);
    }

    private function addOwnAdmin(): User
    {
        return User::factory()->forCompany($this->perjoda)->withRole('company_admin')->create(['email' => 'owner@perjoda.com']);
    }

    public function test_without_confirm_it_only_lists_and_deletes_nothing(): void
    {
        $this->addOwnAdmin();

        $this->artisan('transitflow:purge-demo-data')
            ->expectsOutputToContain('Nothing was deleted')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'conductor@perjoda.test']);
        $this->assertSame(1, Franchise::withoutGlobalScopes()->where('case_no', 'CASE 2019-0417')->count());
        $this->assertNotSoftDeleted($this->southline);
    }

    public function test_it_refuses_while_a_demo_account_is_the_only_company_admin(): void
    {
        $this->artisan('transitflow:purge-demo-data', ['--confirm' => true])
            ->expectsOutputToContain('Create your own Company Admin first')
            ->assertFailed();

        $this->assertDatabaseHas('users', ['email' => 'admin@perjoda.test']);
        $this->assertNotSoftDeleted($this->southline);
    }

    public function test_confirm_removes_every_demo_record_and_keeps_the_operators_own_data(): void
    {
        $owner = $this->addOwnAdmin();

        $this->artisan('transitflow:purge-demo-data', ['--confirm' => true])
            ->expectsOutputToContain('Demo data removed')
            ->assertSuccessful();

        // Demo gone.
        $this->assertSoftDeleted($this->southline);
        $this->assertDatabaseMissing('users', ['email' => 'admin@southline.test']);
        foreach (['admin', 'manager', 'staff', 'conductor'] as $who) {
            $this->assertDatabaseMissing('users', ['email' => "{$who}@perjoda.test"]);
        }
        $this->assertSame(0, Franchise::withoutGlobalScopes()->where('case_no', 'CASE 2019-0417')->count());
        $this->assertSame(0, RouteStop::withoutGlobalScopes()->whereIn('name', ['LANGKAAN', 'EPZA (ROSARIO)'])->count());
        $this->assertSame(0, Route::withoutGlobalScopes()->where('origin', 'SM PALA-PALA')->count());
        $this->assertSame(0, Bus::withoutGlobalScopes()->where('bus_number', 'PERJODA-003')->count());
        $this->assertSame(0, Driver::withoutGlobalScopes()->where('name', 'JUAN DELA CRUZ')->count());
        $this->assertSame(0, Terminal::withoutGlobalScopes()->where('name', 'SM PALA-PALA')->count());
        $this->assertSame(0, PassengerType::withoutGlobalScopes()->where('company_id', $this->perjoda->id)->count());
        $this->assertSame(0, Trip::withoutGlobalScopes()->count());
        $this->assertSame(0, Ticket::withoutGlobalScopes()->count());

        // The operator's own data and company stay.
        $this->assertNotSoftDeleted($this->perjoda);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertSame(1, Driver::withoutGlobalScopes()->where('name', 'CHARLIE')->count());
        $this->assertSame(1, Bus::withoutGlobalScopes()->where('bus_number', 'BUS-REAL-1')->count());
        $this->assertSame(1, Franchise::withoutGlobalScopes()->where('case_no', 'CASE 2026-0001')->count());
        $this->assertSame(2, RouteStop::withoutGlobalScopes()->whereIn('name', ['A', 'B'])->count());
    }
}
