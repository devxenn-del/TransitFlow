<?php

namespace Tests\Feature\Reports;

use App\Models\Bus;
use App\Models\Company;
use App\Models\Dispatch;
use App\Models\EvChargingSession;
use App\Models\FuelRecord;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private Bus $bus;

    private User $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->bus = Bus::factory()->for($this->company)->create(['bus_number' => 'BUS-01', 'plate_number' => 'ABC-1234']);
        $this->office = User::factory()->forCompany($this->company)->withRole('office')->create();
    }

    /**
     * Build an arrived trip on $this->bus with the given tickets.
     *
     * @param  list<array{fare:int, boarding_type?:string, payment_method?:string}>  $tickets
     */
    private function tripWithTickets(array $tickets, string $shift = 'Morning', ?string $opDate = null): Trip
    {
        $opDate ??= now()->toDateString();

        $trip = Trip::factory()->for($this->company)->arrived()->create([
            'bus_id' => $this->bus->id,
            'bus_number' => $this->bus->bus_number,
            'op_date' => $opDate,
            'shift' => $shift,
            'started_at' => $opDate.' 08:00:00',
        ]);

        foreach ($tickets as $ticket) {
            Ticket::factory()->for($this->company)->create([
                'trip_id' => $trip->id,
                'fare' => $ticket['fare'],
                'boarding_type' => $ticket['boarding_type'] ?? 'Terminal',
                'payment_method' => $ticket['payment_method'] ?? 'Cash',
                'issued_at' => $opDate.' 08:30:00',
            ]);
        }

        return $trip;
    }

    public function test_income_monitoring_sums_fares_per_bus_for_the_day_and_shows_idle_buses_as_zero(): void
    {
        $this->tripWithTickets([['fare' => 15], ['fare' => 20], ['fare' => 45]]);
        $idle = Bus::factory()->for($this->company)->create(['bus_number' => 'BUS-99']);

        Sanctum::actingAs($this->office);

        $response = $this->getJson('/api/company/reports/income?period=daily&date='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.total_income', 80)
            ->assertJsonPath('data.total_trips', 1)
            ->assertJsonPath('data.total_tickets', 3);

        $rows = collect($response->json('data.rows'))->keyBy('bus_number');
        $this->assertEquals(80.0, $rows['BUS-01']['income']);
        $this->assertSame(1, $rows['BUS-01']['trip_count']);
        $this->assertEquals(0.0, $rows['BUS-99']['income']);
        $this->assertSame(0, $rows['BUS-99']['trip_count']);
    }

    public function test_income_monitoring_never_counts_another_companys_tickets(): void
    {
        $this->tripWithTickets([['fare' => 15]]);

        $other = Company::factory()->create();
        $otherBus = Bus::factory()->for($other)->create();
        $otherTrip = Trip::factory()->for($other)->arrived()->create([
            'bus_id' => $otherBus->id,
            'op_date' => now()->toDateString(),
            'started_at' => now(),
        ]);
        Ticket::factory()->for($other)->create(['trip_id' => $otherTrip->id, 'fare' => 999, 'issued_at' => now()]);

        Sanctum::actingAs($this->office);

        $this->getJson('/api/company/reports/income?period=daily&date='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.total_income', 15);
    }

    public function test_income_monitoring_requires_the_reports_view_permission(): void
    {
        $stranger = User::factory()->forCompany($this->company)->withRole('conductor')->create();

        Sanctum::actingAs($stranger);

        $this->getJson('/api/company/reports/income')->assertForbidden();
    }

    public function test_income_monitoring_exports_as_pdf_and_xlsx(): void
    {
        $this->tripWithTickets([['fare' => 15]]);

        Sanctum::actingAs($this->office);

        $this->get('/api/company/reports/income?format=pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->get('/api/company/reports/income?format=xlsx')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_every_report_renders_a_pdf_and_an_xlsx_without_error(): void
    {
        $trip = $this->tripWithTickets([['fare' => 15, 'boarding_type' => 'Pickup']]);
        Dispatch::factory()->forTrip($trip)->create(['amount' => 10]);
        FuelRecord::factory()->for($this->company)->create(['bus_id' => $this->bus->id, 'fueled_at' => now()]);

        Sanctum::actingAs($this->office);

        $today = now()->toDateString();
        $endpoints = [
            "reports/income?date={$today}",
            "reports/trip-income?bus_id={$this->bus->id}&date={$today}",
            'reports/fuel-energy?',
        ];

        foreach ($endpoints as $query) {
            $base = "/api/company/{$query}";
            $this->get($base.'&format=pdf')
                ->assertOk()
                ->assertHeader('content-type', 'application/pdf');
            $this->get($base.'&format=xlsx')
                ->assertOk()
                ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
    }

    public function test_trip_income_breaks_a_bus_day_into_per_trip_rows_with_net_after_dispatch(): void
    {
        $trip = $this->tripWithTickets([
            ['fare' => 30, 'boarding_type' => 'Terminal'],
            ['fare' => 20, 'boarding_type' => 'Pickup'],
        ]);
        Dispatch::factory()->forTrip($trip)->create(['amount' => 15]);

        Sanctum::actingAs($this->office);

        $this->getJson('/api/company/reports/trip-income?bus_id='.$this->bus->id.'&date='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.total_received', 50)
            ->assertJsonPath('data.total_dispatch', 15)
            ->assertJsonPath('data.total_income', 35)
            ->assertJsonPath('data.rows.0.terminal_count', 1)
            ->assertJsonPath('data.rows.0.pickup_count', 1)
            ->assertJsonPath('data.rows.0.net_total', 35);
    }

    public function test_fuel_energy_report_totals_fuel_cost_and_charging_sessions(): void
    {
        FuelRecord::factory()->for($this->company)->create([
            'bus_id' => $this->bus->id, 'fuel_type' => 'Diesel',
            'liters' => 40, 'price_per_liter' => 60, 'amount_paid' => 2400, 'fueled_at' => now(),
        ]);
        EvChargingSession::factory()->for($this->company)->create([
            'bus_id' => $this->bus->id, 'status' => 'Completed',
            'started_at' => now()->subHours(2), 'ended_at' => now(),
            'battery_start_pct' => 20, 'battery_end_pct' => 90,
        ]);

        Sanctum::actingAs($this->office);

        $this->getJson('/api/company/reports/fuel-energy')
            ->assertOk()
            ->assertJsonPath('data.fuel.totals.total_cost', 2400)
            ->assertJsonPath('data.fuel.totals.total_liters', 40)
            ->assertJsonPath('data.charging.totals.sessions', 1)
            ->assertJsonPath('data.charging.totals.completed', 1);
    }

    public function test_dashboard_reports_todays_activity_and_a_seven_day_series(): void
    {
        $this->tripWithTickets([['fare' => 15, 'payment_method' => 'Cash'], ['fare' => 20, 'payment_method' => 'QR']]);

        Sanctum::actingAs($this->office);

        $this->getJson('/api/company/dashboard')
            ->assertOk()
            ->assertJsonPath('data.today.trips', 1)
            ->assertJsonPath('data.today.tickets', 2)
            ->assertJsonPath('data.today.collected', 35)
            ->assertJsonCount(7, 'data.weekly_collection')
            ->assertJsonPath('data.buses.total', 1);
    }

    public function test_dashboard_requires_the_dashboard_view_permission(): void
    {
        $stranger = User::factory()->forCompany($this->company)->withRole('conductor')->withoutPermissions()->create();

        Sanctum::actingAs($stranger);

        $this->getJson('/api/company/dashboard')->assertForbidden();
    }
}
