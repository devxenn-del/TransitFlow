<?php

namespace Tests\Feature\Operations;

use App\Actions\RollUpBusDayCashCount;
use App\Models\Bus;
use App\Models\CashCount;
use App\Models\Company;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class RemittanceReceiptTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private User $cashier;   // office — can view + receive

    private User $manager;   // can void + approve

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->withSettings()->create();
        $this->cashier = User::factory()->forCompany($this->company)->withRole('office')->create();
        $this->manager = User::factory()->forCompany($this->company)->withRole('manager')->create();
    }

    private function arrivedTrip(float $remitted, array $overrides = []): Trip
    {
        return Trip::factory()->for($this->company)->arrived()->create(array_merge([
            'op_date' => now()->toDateString(),
            'shift' => 'Morning',
            'remitted_amount' => $remitted,
        ], $overrides));
    }

    /** @return array<string,int> */
    private function den(array $q = []): array
    {
        return array_merge(['q100' => 1, 'q50' => 1, 'q20' => 2], $q); // ₱190
    }

    public function test_receiving_records_the_count_stamps_the_trip_and_builds_the_rollup(): void
    {
        $trip = $this->arrivedTrip(190);
        Sanctum::actingAs($this->cashier);

        $this->postJson("/api/company/remittances/{$trip->id}/receive", $this->den())
            ->assertCreated()
            ->assertJsonPath('data.counted_total', 190)
            ->assertJsonPath('data.expected_amount', 190)
            ->assertJsonPath('data.variance', 0)
            ->assertJsonPath('remittance.stage', 'received');

        $this->assertDatabaseHas('remittance_cash_counts', ['trip_id' => $trip->id, 'status' => 'Received', 'counted_total' => 190]);
        $this->assertDatabaseHas('trips', ['id' => $trip->id, 'remittance_received_by' => $this->cashier->id]);
        $this->assertDatabaseHas('cash_count_history', ['trip_id' => $trip->id, 'type' => 'RemitReceived', 'amount' => 190]);
        $this->assertDatabaseHas('bus_day_cash_counts', [
            'bus_id' => $trip->bus_id, 'shift' => 'Morning',
            'remitted_total' => 190, 'counted_total' => 190, 'trip_count' => 1, 'variance' => 0,
        ]);
    }

    public function test_rollup_sums_every_received_count_for_the_bus_day_shift(): void
    {
        $bus = Bus::factory()->for($this->company)->create();
        $a = $this->arrivedTrip(190, ['bus_id' => $bus->id]);
        $b = $this->arrivedTrip(100, ['bus_id' => $bus->id]);

        Sanctum::actingAs($this->cashier);
        $this->postJson("/api/company/remittances/{$a->id}/receive", $this->den())->assertCreated();       // 190
        $this->postJson("/api/company/remittances/{$b->id}/receive", ['q100' => 1])->assertCreated();      // 100

        $this->assertDatabaseHas('bus_day_cash_counts', [
            'bus_id' => $bus->id, 'shift' => 'Morning',
            'remitted_total' => 290, 'counted_total' => 290, 'trip_count' => 2, 'variance' => 0,
        ]);
    }

    public function test_a_short_count_records_a_negative_variance(): void
    {
        $trip = $this->arrivedTrip(200);
        Sanctum::actingAs($this->cashier);

        $this->postJson("/api/company/remittances/{$trip->id}/receive", $this->den()) // ₱190 vs ₱200
            ->assertCreated()
            ->assertJsonPath('data.variance', -10);
    }

    public function test_cannot_receive_twice_or_receive_a_live_trip(): void
    {
        $trip = $this->arrivedTrip(190);
        Sanctum::actingAs($this->cashier);

        $this->postJson("/api/company/remittances/{$trip->id}/receive", $this->den())->assertCreated();
        $this->postJson("/api/company/remittances/{$trip->id}/receive", $this->den())->assertJsonValidationErrorFor('trip');

        $live = Trip::factory()->for($this->company)->onTrip()->create();
        $this->postJson("/api/company/remittances/{$live->id}/receive", $this->den())->assertJsonValidationErrorFor('trip');
    }

    public function test_approve_needs_a_received_count_and_records_excess_short(): void
    {
        $trip = $this->arrivedTrip(200);

        Sanctum::actingAs($this->manager);
        $this->postJson("/api/company/remittances/{$trip->id}/approve")->assertJsonValidationErrorFor('trip');

        Sanctum::actingAs($this->cashier);
        $this->postJson("/api/company/remittances/{$trip->id}/receive", $this->den())->assertCreated(); // counted 190

        Sanctum::actingAs($this->manager);
        $this->postJson("/api/company/remittances/{$trip->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.remittance_approved_by', $this->manager->name);

        $this->assertDatabaseHas('trips', [
            'id' => $trip->id, 'remittance_short_amount' => 10, 'remittance_excess_amount' => 0,
            'remittance_approved_by' => $this->manager->id,
        ]);

        $this->postJson("/api/company/remittances/{$trip->id}/approve")->assertJsonValidationErrorFor('trip');
    }

    public function test_only_a_manager_can_void_and_it_rolls_the_count_back_out(): void
    {
        $this->manager->forceFill(['void_pin_hash' => Hash::make('2468')])->save();
        $trip = $this->arrivedTrip(190);

        Sanctum::actingAs($this->cashier);
        $this->postJson("/api/company/remittances/{$trip->id}/receive", $this->den())->assertCreated();

        // office cannot void
        $this->postJson("/api/company/remittances/{$trip->id}/void", ['reason' => 'x'])->assertForbidden();

        Sanctum::actingAs($this->manager);
        $this->postJson("/api/company/remittances/{$trip->id}/void", ['reason' => 'Wrong bus', 'pin' => '2468'])
            ->assertOk()
            ->assertJsonPath('data.status', 'Voided');

        $this->assertDatabaseHas('trips', ['id' => $trip->id, 'remittance_received_at' => null]);
        $this->assertDatabaseHas('bus_day_cash_counts', [
            'bus_id' => $trip->bus_id, 'shift' => 'Morning',
            'counted_total' => 0, 'remitted_total' => 0, 'trip_count' => 0,
        ]);
        $this->assertDatabaseHas('cash_count_history', ['trip_id' => $trip->id, 'type' => 'RemitVoid']);

        // the trip can be received again
        Sanctum::actingAs($this->cashier);
        $this->postJson("/api/company/remittances/{$trip->id}/receive", $this->den())->assertCreated();
    }

    public function test_void_requires_the_managers_pin_and_locks_out_after_five_tries(): void
    {
        $this->manager->forceFill(['void_pin_hash' => Hash::make('1111')])->save();
        $trip = $this->arrivedTrip(190);
        Sanctum::actingAs($this->cashier);
        $this->postJson("/api/company/remittances/{$trip->id}/receive", $this->den())->assertCreated();

        Sanctum::actingAs($this->manager);
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson("/api/company/remittances/{$trip->id}/void", ['reason' => 'x', 'pin' => '0000'])
                ->assertJsonValidationErrorFor('pin');
        }
        $this->assertDatabaseCount('cash_count_void_attempts', 5);
        $this->manager->refresh();
        $this->assertNotNull($this->manager->void_pin_locked_until);

        $this->postJson("/api/company/remittances/{$trip->id}/void", ['reason' => 'x', 'pin' => '1111'])
            ->assertJsonValidationErrorFor('pin');
    }

    public function test_an_approved_remittance_cannot_be_voided(): void
    {
        $this->manager->forceFill(['void_pin_hash' => Hash::make('1111')])->save();
        $trip = $this->arrivedTrip(190);

        Sanctum::actingAs($this->cashier);
        $this->postJson("/api/company/remittances/{$trip->id}/receive", $this->den())->assertCreated();
        Sanctum::actingAs($this->manager);
        $this->postJson("/api/company/remittances/{$trip->id}/approve")->assertOk();

        $this->postJson("/api/company/remittances/{$trip->id}/void", ['reason' => 'x', 'pin' => '1111'])
            ->assertJsonValidationErrorFor('remittance');
    }

    public function test_manager_flags_then_clears(): void
    {
        $trip = $this->arrivedTrip(190);
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/company/remittances/{$trip->id}/flag", ['flagged' => true, 'note' => 'off'])
            ->assertOk()->assertJsonPath('data.remittance_flagged', true);
        $this->postJson("/api/company/remittances/{$trip->id}/flag", ['flagged' => false])
            ->assertOk()->assertJsonPath('data.remittance_flagged', false);
    }

    public function test_a_manager_can_re_tally_the_rollup_denominations_with_the_void_pin(): void
    {
        $manager = User::factory()->forCompany($this->company)->withRole('manager')->create();
        $manager->forceFill(['void_pin_hash' => Hash::make('7777')])->save();

        $trip = $this->arrivedTrip(190);
        Sanctum::actingAs($this->cashier);
        $this->postJson("/api/company/remittances/{$trip->id}/receive", $this->den())->assertCreated(); // counted 190

        $rollup = CashCount::query()->where('bus_id', $trip->bus_id)->firstOrFail();
        $this->assertSame(190, (int) $rollup->counted_total);

        // cashier cannot adjust
        Sanctum::actingAs($this->cashier);
        $this->postJson("/api/company/cash-counts/{$rollup->id}/adjust", ['q100' => 2, 'reason' => 'x'])->assertForbidden();

        // manager adjusts to ₱250 (2x100 + 1x50)
        Sanctum::actingAs($manager);
        $this->postJson("/api/company/cash-counts/{$rollup->id}/adjust", [
            'q100' => 2, 'q50' => 1, 'reason' => 'Re-counted the envelope', 'pin' => '7777',
        ])
            ->assertOk()
            ->assertJsonPath('data.counted_total', 250)
            ->assertJsonPath('data.variance', 60)
            ->assertJsonPath('data.adjusted_by', $manager->name);

        // a later remittance void must NOT clobber the manual count
        $trip->remittanceCashCount->update(['status' => 'Voided']);
        app(RollUpBusDayCashCount::class)->handle($this->company->id, $trip->bus_id, $trip->op_date->toDateString(), $trip->shift);
        $this->assertSame(250, (int) $rollup->fresh()->counted_total);
    }

    public function test_a_second_cashier_cannot_receive_while_someone_holds_the_lock(): void
    {
        $other = User::factory()->forCompany($this->company)->withRole('office')->create();
        $trip = $this->arrivedTrip(190);

        Sanctum::actingAs($other);
        $this->postJson("/api/company/remittances/{$trip->id}/lock")->assertOk()->assertJsonPath('locked_by_me', true);

        Sanctum::actingAs($this->cashier);
        $this->postJson("/api/company/remittances/{$trip->id}/lock")->assertStatus(423);
        $this->postJson("/api/company/remittances/{$trip->id}/receive", $this->den())->assertStatus(423);

        // the lock holder still can, and receiving clears the lock
        Sanctum::actingAs($other);
        $this->postJson("/api/company/remittances/{$trip->id}/receive", $this->den())->assertCreated();
        $this->assertDatabaseHas('trips', ['id' => $trip->id, 'remittance_locked_by' => null]);
    }

    public function test_an_expired_lock_does_not_block_and_can_be_taken_over(): void
    {
        $other = User::factory()->forCompany($this->company)->withRole('office')->create();
        $trip = $this->arrivedTrip(190, [
            'remittance_locked_by' => $other->id,
            'remittance_locked_at' => now()->subMinutes(10),
        ]);

        Sanctum::actingAs($this->cashier);
        $this->postJson("/api/company/remittances/{$trip->id}/lock")->assertOk();
        $this->assertDatabaseHas('trips', ['id' => $trip->id, 'remittance_locked_by' => $this->cashier->id]);
    }

    public function test_the_remittance_desk_is_company_scoped(): void
    {
        $other = Company::factory()->withSettings()->create();
        $otherTrip = Trip::factory()->for($other)->arrived()->create(['remitted_amount' => 100]);

        Sanctum::actingAs($this->cashier);
        $this->getJson('/api/company/remittances')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/company/remittances/{$otherTrip->id}/receive", $this->den())->assertNotFound();
    }
}
