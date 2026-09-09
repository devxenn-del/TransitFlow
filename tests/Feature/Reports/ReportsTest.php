<?php

namespace Tests\Feature\Reports;

use App\Models\Bus;
use App\Models\CashCount;
use App\Models\Company;
use App\Models\Dispatch;
use App\Models\EvChargingSession;
use App\Models\FuelRecord;
use App\Models\OpExpense;
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
        OpExpense::factory()->for($this->company)->create(['bus_id' => $this->bus->id, 'op_date' => now()->toDateString(), 'amount' => 40]);
        CashCount::factory()->for($this->company)->create(['bus_id' => $this->bus->id, 'op_date' => now()->toDateString(), 'q100' => 3, 'counted_total' => 300, 'net_cash' => 260]);
        FuelRecord::factory()->for($this->company)->create(['bus_id' => $this->bus->id, 'fueled_at' => now()]);

        Sanctum::actingAs($this->office);

        $today = now()->toDateString();
        $endpoints = [
            "reports/income?date={$today}",
            "reports/trip-income?bus_id={$this->bus->id}&date={$today}",
            "reports/daily-operations?from={$today}&to={$today}",
            "reports/expenses?from={$today}&to={$today}",
            "reports/cash-count?from={$today}&to={$today}",
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

    public function test_daily_operations_shift_nets_sum_to_remaining_income(): void
    {
        // Morning: 100 fares, 20 dispatch, 30 expense  → net 50
        $morning = $this->tripWithTickets([['fare' => 60], ['fare' => 40]], 'Morning');
        Dispatch::factory()->forTrip($morning)->create(['amount' => 20]);
        OpExpense::factory()->for($this->company)->create([
            'bus_id' => $this->bus->id, 'op_date' => now()->toDateString(), 'shift' => 'Morning',
            'amount' => 30, 'status' => 'Active',
        ]);

        // Evening: 50 fares, no dispatch, no expense → net 50
        $this->tripWithTickets([['fare' => 50]], 'Evening');

        Sanctum::actingAs($this->office);

        $data = $this->getJson('/api/company/reports/daily-operations?from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->json('data');

        $row = collect($data['rows'])->firstWhere('bus_number', 'BUS-01');
        $this->assertEquals(150.0, $row['gross_income']);
        $this->assertEquals(100.0, $row['remaining_income']); // 150 - 20 dispatch - 30 expense
        $this->assertEquals(50.0, $row['morning_net']);
        $this->assertEquals(50.0, $row['evening_net']);
        $this->assertEquals(
            $row['remaining_income'],
            round($row['morning_net'] + $row['evening_net'], 2),
        );
        $this->assertEquals(100.0, $data['summary']['remaining_income']);
    }

    public function test_expense_report_groups_by_op_date_and_excludes_voided_rows(): void
    {
        OpExpense::factory()->for($this->company)->create([
            'bus_id' => $this->bus->id, 'op_date' => now()->toDateString(), 'shift' => 'Morning',
            'amount' => 250, 'status' => 'Active', 'description' => 'Diesel top-up',
        ]);
        OpExpense::factory()->for($this->company)->voided()->create([
            'bus_id' => $this->bus->id, 'op_date' => now()->toDateString(), 'amount' => 9999,
        ]);

        Sanctum::actingAs($this->office);

        $this->getJson('/api/company/reports/expenses?from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.grand_total', 250)
            ->assertJsonCount(1, 'data.days')
            ->assertJsonPath('data.days.0.total', 250)
            ->assertJsonCount(1, 'data.days.0.items');
    }

    public function test_cash_count_report_totals_denominations_and_counted_amounts(): void
    {
        CashCount::factory()->for($this->company)->create([
            'bus_id' => $this->bus->id, 'op_date' => now()->toDateString(), 'shift' => 'Morning',
            'q1000' => 2, 'q100' => 5, 'counted_total' => 2500, 'remitted_total' => 2500, 'net_cash' => 2500,
        ]);

        Sanctum::actingAs($this->office);

        $this->getJson('/api/company/reports/cash-count?from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.grand_total', 2500)
            ->assertJsonPath('data.denom_totals.1000', 2)
            ->assertJsonPath('data.denom_totals.100', 5)
            ->assertJsonCount(1, 'data.rows');
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
