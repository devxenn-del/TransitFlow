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

class DispatchAndRemittanceTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private User $conductor;

    private Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->conductor = User::factory()->forCompany($this->company)->withRole('conductor')->create();
        $this->trip = Trip::factory()->for($this->company)->onTrip()->create(['conductor_id' => $this->conductor->id]);

        Sanctum::actingAs($this->conductor);
    }

    private function sellTicket(float $fare, string $method = 'Cash', string $boarding = 'Terminal', ?string $article = null): void
    {
        $this->trip->tickets()->create([
            'company_id' => $this->company->id,
            'passenger_type_id' => PassengerType::factory()->for($this->company)->create()->id,
            'boarding_type' => $boarding,
            'payment_method' => $method,
            'article_label' => $article,
            'fare' => $fare,
            'issued_at' => now(),
        ]);
    }

    public function test_conductor_records_a_barker_dispatch_on_the_live_trip(): void
    {
        $this->postJson('/api/conductor/dispatches', ['barker_name' => 'Mang Tomas', 'amount' => 30])
            ->assertCreated()
            ->assertJsonPath('data.barker_name', 'Mang Tomas')
            ->assertJsonPath('data.amount', 30)
            ->assertJsonPath('remittance.total_dispatch', 30);

        $this->assertDatabaseHas('dispatches', [
            'trip_id' => $this->trip->id,
            'company_id' => $this->company->id,
            'barker_name' => 'Mang Tomas',
            'amount' => 30,
        ]);
    }

    public function test_dispatch_reduces_the_suggested_remittance(): void
    {
        $this->sellTicket(100);
        $this->sellTicket(50);
        $this->postJson('/api/conductor/dispatches', ['barker_name' => 'A', 'amount' => 40])->assertCreated();

        $this->getJson("/api/conductor/trips/{$this->trip->id}/remittance")
            ->assertOk()
            ->assertJsonPath('data.collected', 150)
            ->assertJsonPath('data.total_dispatch', 40)
            ->assertJsonPath('data.suggested_remit', 110);
    }

    public function test_remittance_breakdown_splits_by_payment_boarding_and_fare_mode(): void
    {
        $this->sellTicket(50, 'Cash', 'Terminal');
        $this->sellTicket(30, 'QR', 'Pickup');
        $this->sellTicket(10, 'Cash', 'Terminal', 'Student ID');

        $this->getJson("/api/conductor/trips/{$this->trip->id}/remittance")
            ->assertOk()
            ->assertJsonPath('data.collected', 90)
            ->assertJsonPath('data.by_payment_method.Cash', 60)
            ->assertJsonPath('data.by_payment_method.QR', 30)
            ->assertJsonPath('data.by_boarding_type.Terminal', 60)
            ->assertJsonPath('data.by_boarding_type.Pickup', 30)
            ->assertJsonPath('data.by_fare_mode.passenger', 80)
            ->assertJsonPath('data.by_fare_mode.article', 10);
    }

    public function test_conductor_can_delete_a_dispatch_while_the_trip_is_live(): void
    {
        $id = $this->postJson('/api/conductor/dispatches', ['barker_name' => 'A', 'amount' => 25])
            ->assertCreated()->json('data.id');

        $this->deleteJson("/api/conductor/trips/{$this->trip->id}/dispatches/{$id}")
            ->assertOk()
            ->assertJsonPath('remittance.total_dispatch', 0);

        $this->assertDatabaseMissing('dispatches', ['id' => $id]);
    }

    public function test_a_dispatch_cannot_be_recorded_once_the_trip_has_ended(): void
    {
        $this->trip->update(['status' => 'Arrived', 'ended_at' => now()]);

        $this->postJson('/api/conductor/dispatches', ['barker_name' => 'A', 'amount' => 25])
            ->assertNotFound();
    }

    public function test_staff_without_dispatch_issue_cannot_record_a_dispatch(): void
    {
        Sanctum::actingAs(User::factory()->forCompany($this->company)->create()); // office

        $this->postJson('/api/conductor/dispatches', ['barker_name' => 'A', 'amount' => 25])
            ->assertForbidden();
    }

    public function test_a_conductor_cannot_dispatch_against_another_conductors_trip(): void
    {
        $mate = User::factory()->forCompany($this->company)->withRole('conductor')->create();
        Sanctum::actingAs($mate);

        // The mate has no live trip of their own.
        $this->postJson('/api/conductor/dispatches', ['barker_name' => 'A', 'amount' => 25])
            ->assertNotFound();

        $this->deleteJson("/api/conductor/trips/{$this->trip->id}/dispatches/1")
            ->assertForbidden();
    }
}
