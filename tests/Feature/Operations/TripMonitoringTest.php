<?php

namespace Tests\Feature\Operations;

use App\Models\Company;
use App\Models\PassengerType;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class TripMonitoringTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private User $admin;

    private User $conductor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->forCompany($this->company)->withRole('company_admin')->create();
        $this->conductor = User::factory()->forCompany($this->company)->withRole('conductor')->create();
    }

    private function trip(array $overrides = []): Trip
    {
        return Trip::factory()->for($this->company)->create(array_merge([
            'conductor_id' => $this->conductor->id,
        ], $overrides));
    }

    private function ticket(Trip $trip, float $fare): void
    {
        $trip->tickets()->create([
            'company_id' => $this->company->id,
            'passenger_type_id' => PassengerType::factory()->for($this->company)->create()->id,
            'boarding_type' => 'Terminal',
            'payment_method' => 'Cash',
            'fare' => $fare,
            'issued_at' => now(),
        ]);
    }

    public function test_admin_lists_every_trip_in_the_company_with_a_remittance_block(): void
    {
        $this->trip(['status' => 'OnTrip']);
        $this->trip(['status' => 'Arrived', 'ended_at' => now()]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/company/trip-monitor')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.remittance.suggested_remit', 0);
    }

    public function test_pending_approval_filter_returns_only_unapproved_completed_trips(): void
    {
        $this->trip(['status' => 'OnTrip']);
        $done = $this->trip(['status' => 'Arrived', 'ended_at' => now()]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/company/trip-monitor?pending_approval=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $done->id);
    }

    public function test_admin_force_ends_a_hanging_trip(): void
    {
        $trip = $this->trip(['status' => 'OnTrip']);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/company/trip-monitor/{$trip->id}/force-end", ['reason' => 'Conductor unreachable'])
            ->assertOk()
            ->assertJsonPath('data.status', 'Arrived')
            ->assertJsonPath('data.force_ended_by', $this->admin->name)
            ->assertJsonPath('data.force_ended_reason', 'Conductor unreachable');

        $this->assertDatabaseHas('trips', ['id' => $trip->id, 'force_ended_by' => $this->admin->id]);
    }

    public function test_force_end_requires_a_live_trip(): void
    {
        $trip = $this->trip(['status' => 'Arrived', 'ended_at' => now()]);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/company/trip-monitor/{$trip->id}/force-end", ['reason' => 'x'])
            ->assertJsonValidationErrorFor('trip');
    }

    public function test_a_conductor_cannot_reach_trip_monitoring(): void
    {
        Sanctum::actingAs($this->conductor);

        $this->getJson('/api/company/trip-monitor')->assertForbidden();
        $trip = $this->trip(['status' => 'OnTrip']);
        $this->postJson("/api/company/trip-monitor/{$trip->id}/force-end", ['reason' => 'x'])->assertForbidden();
    }

    public function test_trip_monitoring_is_company_scoped(): void
    {
        $otherCompany = Company::factory()->create();
        $otherTrip = Trip::factory()->for($otherCompany)->create();

        Sanctum::actingAs($this->admin);

        $this->getJson("/api/company/trip-monitor/{$otherTrip->id}")->assertNotFound();
        $this->postJson("/api/company/trip-monitor/{$otherTrip->id}/force-end", ['reason' => 'x'])->assertNotFound();
    }
}
