<?php

namespace Tests\Feature\Operations;

use App\Actions\RollUpBusDayCashCount;
use App\Models\Bus;
use App\Models\Company;
use App\Models\OpExpense;
use App\Models\RemittanceCashCount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class OperationalExpenseTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private Bus $bus;

    private User $clerk;    // office — view + create

    private User $manager;  // + void

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->withSettings()->create();
        $this->bus = Bus::factory()->for($this->company)->create();
        $this->clerk = User::factory()->forCompany($this->company)->withRole('office')->create();
        $this->manager = User::factory()->forCompany($this->company)->withRole('manager')->create();
    }

    /** @return array<string,mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'bus_id' => $this->bus->id,
            'op_date' => now()->toDateString(),
            'shift' => 'Morning',
            'category' => 'Fuel',
            'description' => 'Diesel top-up',
            'q100' => 5, 'q20' => 2, // ₱540
        ], $overrides);
    }

    public function test_recording_an_expense_totals_denominations_writes_history_and_nets_the_rollup(): void
    {
        // A received remittance so the rollup row exists with cash in it.
        RemittanceCashCount::factory()->create([
            'company_id' => $this->company->id, 'bus_id' => $this->bus->id,
            'op_date' => now()->toDateString(), 'shift' => 'Morning',
            'q1000' => 1, 'counted_total' => 1000, 'expected_amount' => 1000, 'status' => 'Received',
        ]);
        app(RollUpBusDayCashCount::class)->handle($this->company->id, $this->bus->id, now()->toDateString(), 'Morning');

        Sanctum::actingAs($this->clerk);

        $this->postJson('/api/company/expenses', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.amount', 540)
            ->assertJsonPath('data.status', 'Active')
            ->assertJsonPath('data.denominations.q100', 5);

        $this->assertDatabaseHas('op_day_expenses', ['bus_id' => $this->bus->id, 'amount' => 540, 'status' => 'Active']);
        $this->assertDatabaseHas('cash_count_history', ['type' => 'Expense', 'amount' => -540]);
        $this->assertDatabaseHas('bus_day_cash_counts', [
            'bus_id' => $this->bus->id, 'shift' => 'Morning',
            'counted_total' => 1000, 'expenses_total' => 540, 'net_cash' => 460, 'expense_count' => 1,
        ]);
    }

    public function test_a_zero_expense_is_rejected(): void
    {
        Sanctum::actingAs($this->clerk);

        $this->postJson('/api/company/expenses', $this->payload(['q100' => 0, 'q20' => 0]))
            ->assertJsonValidationErrorFor('amount');
    }

    public function test_only_a_manager_can_void_and_it_restores_the_cash(): void
    {
        $this->manager->forceFill(['void_pin_hash' => Hash::make('4444')])->save();

        Sanctum::actingAs($this->clerk);
        $id = $this->postJson('/api/company/expenses', $this->payload())->assertCreated()->json('data.id');

        // office cannot void
        $this->postJson("/api/company/expenses/{$id}/void", ['reason' => 'x'])->assertForbidden();

        Sanctum::actingAs($this->manager);
        $this->postJson("/api/company/expenses/{$id}/void", ['reason' => 'Wrong bus', 'pin' => '4444'])
            ->assertOk()
            ->assertJsonPath('data.status', 'Voided');

        $this->assertDatabaseHas('cash_count_history', ['type' => 'ExpenseVoid', 'amount' => 540]);
        $this->assertDatabaseHas('bus_day_cash_counts', [
            'bus_id' => $this->bus->id, 'shift' => 'Morning', 'expenses_total' => 0, 'expense_count' => 0,
        ]);
    }

    public function test_void_needs_the_managers_pin(): void
    {
        $this->manager->forceFill(['void_pin_hash' => Hash::make('1111')])->save();
        $expense = OpExpense::factory()->for($this->company)->create(['bus_id' => $this->bus->id]);

        Sanctum::actingAs($this->manager);
        $this->postJson("/api/company/expenses/{$expense->id}/void", ['reason' => 'x', 'pin' => '9999'])
            ->assertJsonValidationErrorFor('pin');
    }

    public function test_a_conductor_has_no_access_to_expenses(): void
    {
        Sanctum::actingAs(User::factory()->forCompany($this->company)->withRole('conductor')->create());

        $this->getJson('/api/company/expenses')->assertForbidden();
        $this->postJson('/api/company/expenses', $this->payload())->assertForbidden();
    }

    public function test_expenses_are_company_scoped(): void
    {
        $other = Company::factory()->withSettings()->create();
        $otherExpense = OpExpense::factory()->for($other)->create();

        Sanctum::actingAs($this->manager);
        $this->getJson('/api/company/expenses')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/company/expenses/{$otherExpense->id}")->assertNotFound();
        $this->postJson("/api/company/expenses/{$otherExpense->id}/void", ['reason' => 'x'])->assertNotFound();
    }
}
