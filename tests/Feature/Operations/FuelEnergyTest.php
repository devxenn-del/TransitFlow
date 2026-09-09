<?php

namespace Tests\Feature\Operations;

use App\Models\Bus;
use App\Models\Company;
use App\Models\EvChargingSession;
use App\Models\FuelRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class FuelEnergyTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private Bus $bus;

    private User $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->bus = Bus::factory()->for($this->company)->create();
        $this->office = User::factory()->forCompany($this->company)->withRole('office')->create();
    }

    public function test_recording_fuel_derives_the_amount_from_liters_and_price(): void
    {
        Sanctum::actingAs($this->office);

        $this->postJson('/api/company/fuel', [
            'bus_id' => $this->bus->id,
            'fuel_type' => 'Diesel',
            'liters' => 42.5,
            'price_per_liter' => 63.20,
            'station' => 'Shell EDSA',
            'odometer' => 128400,
        ])
            ->assertCreated()
            ->assertJsonPath('data.amount_paid', 2686) // 42.5 * 63.20
            ->assertJsonPath('data.fuel_type', 'Diesel')
            ->assertJsonPath('data.recorded_by', $this->office->name);

        $this->assertDatabaseHas('fuel_records', ['bus_id' => $this->bus->id, 'amount_paid' => 2686]);
    }

    public function test_fuel_records_can_be_filtered_and_deleted_with_the_right_permission(): void
    {
        $record = FuelRecord::factory()->for($this->company)->create(['bus_id' => $this->bus->id, 'fuel_type' => 'Gasoline']);
        FuelRecord::factory()->for($this->company)->create(['bus_id' => $this->bus->id, 'fuel_type' => 'Diesel']);

        Sanctum::actingAs($this->office);
        $this->getJson('/api/company/fuel?fuel_type=Gasoline')->assertOk()->assertJsonCount(1, 'data');

        // office has fuel.delete; a conductor does not.
        $this->deleteJson("/api/company/fuel/{$record->id}")->assertNoContent();
        $this->assertDatabaseMissing('fuel_records', ['id' => $record->id]);

        $other = FuelRecord::factory()->for($this->company)->create(['bus_id' => $this->bus->id]);
        Sanctum::actingAs(User::factory()->forCompany($this->company)->withRole('conductor')->create());
        $this->deleteJson("/api/company/fuel/{$other->id}")->assertForbidden();
    }

    public function test_an_ev_charging_session_starts_and_ends_and_computes_the_gain(): void
    {
        Sanctum::actingAs($this->office);

        $id = $this->postJson('/api/company/charging', [
            'bus_id' => $this->bus->id, 'battery_start_pct' => 25, 'location' => 'Depot bay 2',
        ])->assertCreated()->assertJsonPath('data.status', 'Charging')->json('data.id');

        // one active session per bus
        $this->postJson('/api/company/charging', ['bus_id' => $this->bus->id, 'battery_start_pct' => 30])
            ->assertJsonValidationErrorFor('bus_id');

        $this->postJson("/api/company/charging/{$id}/end", ['battery_end_pct' => 92])
            ->assertOk()
            ->assertJsonPath('data.status', 'Completed')
            ->assertJsonPath('data.battery_gained_pct', 67);

        // freed the slot — the bus can charge again
        $this->postJson('/api/company/charging', ['bus_id' => $this->bus->id, 'battery_start_pct' => 40])->assertCreated();
    }

    public function test_ending_below_the_start_battery_is_rejected(): void
    {
        $session = EvChargingSession::factory()->for($this->company)->create([
            'bus_id' => $this->bus->id, 'battery_start_pct' => 50,
        ]);
        Sanctum::actingAs($this->office);

        $this->postJson("/api/company/charging/{$session->id}/end", ['battery_end_pct' => 40])
            ->assertJsonValidationErrorFor('battery_end_pct');
    }

    public function test_fuel_and_charging_are_company_scoped(): void
    {
        $other = Company::factory()->create();
        $otherFuel = FuelRecord::factory()->for($other)->create();
        $otherSession = EvChargingSession::factory()->for($other)->create();

        Sanctum::actingAs($this->office);
        $this->getJson('/api/company/fuel')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/company/charging')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/company/fuel/{$otherFuel->id}")->assertNotFound();
        $this->postJson("/api/company/charging/{$otherSession->id}/end", ['battery_end_pct' => 90])->assertNotFound();
    }
}
