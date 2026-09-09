<?php

namespace Tests\Feature\Operations;

use App\Models\Company;
use App\Models\FareMatrix;
use App\Models\Franchise;
use App\Models\PassengerType;
use App\Models\Route;
use App\Models\Ticket;
use App\Models\TicketGroup;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class GroupTicketTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private Trip $trip;

    private Route $route;

    private PassengerType $regular;

    private PassengerType $senior;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $conductor = User::factory()->forCompany($this->company)->withRole('conductor')->create();
        Sanctum::actingAs($conductor);

        $franchise = Franchise::factory()->for($this->company)->withStops(['A', 'B'])->create();
        $this->route = Route::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'franchise_id' => $franchise->id,
            'origin' => 'A', 'destination' => 'B', 'name' => 'A - B', 'status' => 'Active',
        ]);
        FareMatrix::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'route_id' => $this->route->id, 'amount' => 45, 'status' => 'Active']);

        $this->regular = PassengerType::factory()->for($this->company)->create(['name' => 'Regular', 'discount_percent' => 0]);
        $this->senior = PassengerType::factory()->for($this->company)->discounted(20)->create(['name' => 'Senior']);

        $this->trip = Trip::factory()->for($this->company)->create([
            'conductor_id' => $conductor->id, 'status' => 'Departure',
            'coverage_origin' => 'A', 'coverage_destination' => 'B',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function issueGroup(array $lines, array $overrides = [])
    {
        return $this->postJson('/api/conductor/ticket-groups', array_merge([
            'boarding_type' => 'Terminal',
            'payment_method' => 'Cash',
            'lines' => $lines,
        ], $overrides));
    }

    public function test_a_group_ticket_prices_every_line_and_links_the_rows(): void
    {
        $this->issueGroup([
            ['passenger_type_id' => $this->regular->id, 'route_id' => $this->route->id, 'quantity' => 3],
            ['passenger_type_id' => $this->senior->id, 'route_id' => $this->route->id, 'quantity' => 2],
        ])
            ->assertCreated()
            ->assertJsonPath('group.line_count', 2)
            ->assertJsonPath('group.passenger_count', 5)
            ->assertJsonPath('group.total_fare', 207); // 3*45 + 2*36

        $groupId = TicketGroup::query()->value('id');
        $this->assertSame(5, Ticket::query()->where('ticket_group_id', $groupId)->count());
        $this->assertSame(0, Ticket::query()->whereNull('ticket_group_id')->count());
    }

    public function test_one_bad_line_rolls_the_whole_group_back(): void
    {
        $this->issueGroup([
            ['passenger_type_id' => $this->regular->id, 'route_id' => $this->route->id, 'quantity' => 2],
            ['passenger_type_id' => $this->regular->id, 'route_id' => 999999], // not a priced route
        ])->assertJsonValidationErrorFor('lines.1.route_id');

        $this->assertDatabaseCount('ticket_groups', 0);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_replay_by_client_uuid_returns_the_same_group(): void
    {
        $payload = [
            ['passenger_type_id' => $this->regular->id, 'route_id' => $this->route->id, 'quantity' => 2],
        ];

        $first = $this->issueGroup($payload, ['client_uuid' => 'grp-abc-1'])->assertCreated()->json('group.id');
        $second = $this->issueGroup($payload, ['client_uuid' => 'grp-abc-1'])->assertCreated()->json('group.id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('ticket_groups', 1);
        $this->assertSame(2, Ticket::query()->count());
    }

    public function test_more_than_thirty_lines_is_rejected(): void
    {
        $lines = array_fill(0, 31, ['passenger_type_id' => $this->regular->id, 'route_id' => $this->route->id]);

        $this->issueGroup($lines)->assertJsonValidationErrorFor('lines');
    }

    public function test_the_group_is_not_issued_when_the_trip_is_not_live(): void
    {
        $this->trip->update(['status' => 'Arrived']);

        $this->issueGroup([
            ['passenger_type_id' => $this->regular->id, 'route_id' => $this->route->id],
        ])->assertNotFound();
    }
}
