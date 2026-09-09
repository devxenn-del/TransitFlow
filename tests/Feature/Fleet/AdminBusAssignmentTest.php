<?php

namespace Tests\Feature\Fleet;

use App\Models\AdminBusAssignment;
use App\Models\Bus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class AdminBusAssignmentTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private Bus $bus;

    private User $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->companyAdmin($this->company)->create());
        $this->bus = Bus::factory()->for($this->company)->create();
        $this->office = User::factory()->forCompany($this->company)->withRole('office')->create();
    }

    public function test_an_assignment_is_created(): void
    {
        $response = $this->postJson('/api/company/admin-assignments', [
            'bus_id' => $this->bus->id, 'user_id' => $this->office->id,
            'shift' => 'Morning', 'effective_from' => '2026-09-01',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.bus.id', $this->bus->id)
            ->assertJsonPath('data.user.id', $this->office->id)
            ->assertJsonPath('data.status', 'Active');
    }

    public function test_a_conductor_cannot_hold_a_bus_assignment(): void
    {
        $conductor = User::factory()->forCompany($this->company)->withRole('conductor')->create();

        $this->postJson('/api/company/admin-assignments', [
            'bus_id' => $this->bus->id, 'user_id' => $conductor->id,
            'shift' => 'Morning', 'effective_from' => '2026-09-01',
        ])->assertJsonValidationErrorFor('user_id');
    }

    public function test_the_same_bus_and_shift_cannot_overlap_two_active_holders(): void
    {
        $this->postJson('/api/company/admin-assignments', [
            'bus_id' => $this->bus->id, 'user_id' => $this->office->id,
            'shift' => 'Morning', 'effective_from' => '2026-09-01', 'effective_to' => '2026-09-10',
        ])->assertCreated();

        $other = User::factory()->forCompany($this->company)->withRole('office')->create();

        // Overlaps (2026-09-05 falls inside 09-01..09-10), same shift.
        $this->postJson('/api/company/admin-assignments', [
            'bus_id' => $this->bus->id, 'user_id' => $other->id,
            'shift' => 'Morning', 'effective_from' => '2026-09-05',
        ])->assertJsonValidationErrorFor('bus_id');
    }

    public function test_a_different_shift_on_the_same_bus_and_dates_does_not_conflict(): void
    {
        $this->postJson('/api/company/admin-assignments', [
            'bus_id' => $this->bus->id, 'user_id' => $this->office->id,
            'shift' => 'Morning', 'effective_from' => '2026-09-01',
        ])->assertCreated();

        $other = User::factory()->forCompany($this->company)->withRole('office')->create();

        $this->postJson('/api/company/admin-assignments', [
            'bus_id' => $this->bus->id, 'user_id' => $other->id,
            'shift' => 'Evening', 'effective_from' => '2026-09-01',
        ])->assertCreated();
    }

    public function test_a_non_overlapping_date_range_does_not_conflict(): void
    {
        $this->postJson('/api/company/admin-assignments', [
            'bus_id' => $this->bus->id, 'user_id' => $this->office->id,
            'shift' => 'Morning', 'effective_from' => '2026-09-01', 'effective_to' => '2026-09-10',
        ])->assertCreated();

        $other = User::factory()->forCompany($this->company)->withRole('office')->create();

        $this->postJson('/api/company/admin-assignments', [
            'bus_id' => $this->bus->id, 'user_id' => $other->id,
            'shift' => 'Morning', 'effective_from' => '2026-09-11',
        ])->assertCreated();
    }

    public function test_ending_an_assignment_frees_the_bus_for_a_new_one(): void
    {
        $assignment = AdminBusAssignment::query()->create([
            'company_id' => $this->company->id, 'bus_id' => $this->bus->id, 'user_id' => $this->office->id,
            'shift' => 'Morning', 'effective_from' => '2026-09-01', 'status' => 'Active',
        ]);

        $this->putJson("/api/company/admin-assignments/{$assignment->id}", ['status' => 'Inactive'])
            ->assertOk()->assertJsonPath('data.status', 'Inactive');

        $other = User::factory()->forCompany($this->company)->withRole('office')->create();
        $this->postJson('/api/company/admin-assignments', [
            'bus_id' => $this->bus->id, 'user_id' => $other->id,
            'shift' => 'Morning', 'effective_from' => '2026-09-01',
        ])->assertCreated();
    }

    public function test_assignments_are_company_scoped(): void
    {
        $otherCompany = Company::factory()->create();
        $otherAssignment = AdminBusAssignment::factory()->for($otherCompany)->create();

        $this->getJson('/api/company/admin-assignments')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/company/admin-assignments/{$otherAssignment->id}")->assertNotFound();
    }
}
