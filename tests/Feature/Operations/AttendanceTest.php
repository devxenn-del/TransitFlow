<?php

namespace Tests\Feature\Operations;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private User $conductor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->conductor = User::factory()->forCompany($this->company)->withRole('conductor')->create();
        $this->admin = User::factory()->forCompany($this->company)->withRole('company_admin')->create();
    }

    public function test_toggle_opens_then_closes_a_period(): void
    {
        Sanctum::actingAs($this->conductor);

        $this->postJson('/api/conductor/attendance/toggle')
            ->assertCreated()
            ->assertJsonPath('clocked_in', true)
            ->assertJsonPath('data.clock_in_source', 'web');

        $this->assertDatabaseHas('conductor_attendance', [
            'user_id' => $this->conductor->id, 'company_id' => $this->company->id, 'clock_out_at' => null,
        ]);

        $this->postJson('/api/conductor/attendance/toggle', ['source' => 'app'])
            ->assertCreated()
            ->assertJsonPath('clocked_in', false)
            ->assertJsonPath('data.clock_out_source', 'app');

        $this->assertSame(1, Attendance::query()->where('user_id', $this->conductor->id)->count());
        $this->assertSame(0, Attendance::query()->forUser($this->conductor->id)->open()->count());
    }

    public function test_status_returns_the_open_period_and_todays_log(): void
    {
        Attendance::factory()->create([
            'company_id' => $this->company->id, 'user_id' => $this->conductor->id,
            'clock_in_at' => now()->startOfDay()->addHours(6), 'clock_out_at' => now()->startOfDay()->addHours(9),
            'clock_out_source' => 'web',
        ]);
        Attendance::factory()->create([
            'company_id' => $this->company->id, 'user_id' => $this->conductor->id,
            'clock_in_at' => now()->startOfDay()->addHours(10),
        ]);

        Sanctum::actingAs($this->conductor);

        $this->getJson('/api/conductor/attendance')
            ->assertOk()
            ->assertJsonPath('open.is_open', true)
            ->assertJsonCount(2, 'today');
    }

    public function test_a_second_toggle_never_opens_a_parallel_period(): void
    {
        Sanctum::actingAs($this->conductor);

        $this->postJson('/api/conductor/attendance/toggle')->assertCreated();
        $this->postJson('/api/conductor/attendance/toggle')->assertCreated(); // closes
        $this->postJson('/api/conductor/attendance/toggle')->assertCreated(); // opens a new one

        $this->assertSame(1, Attendance::query()->forUser($this->conductor->id)->open()->count());
        $this->assertSame(2, Attendance::query()->where('user_id', $this->conductor->id)->count());
    }

    public function test_admin_lists_and_force_closes_a_forgotten_clock_out(): void
    {
        $period = Attendance::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->conductor->id,
            'clock_in_at' => now()->subHours(12),
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/company/attendance?open=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.name', $this->conductor->name);

        $this->postJson("/api/company/attendance/{$period->id}/close", ['note' => 'Forgot to clock out'])
            ->assertOk()
            ->assertJsonPath('data.is_open', false)
            ->assertJsonPath('data.closed_by', $this->admin->name);

        $this->assertDatabaseHas('conductor_attendance', [
            'id' => $period->id, 'closed_by' => $this->admin->id, 'closed_note' => 'Forgot to clock out',
        ]);
    }

    public function test_closing_an_already_closed_period_is_rejected(): void
    {
        $period = Attendance::factory()->closed()->create(['company_id' => $this->company->id, 'user_id' => $this->conductor->id]);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/company/attendance/{$period->id}/close", ['note' => 'x'])
            ->assertJsonValidationErrorFor('attendance');
    }

    public function test_a_conductor_cannot_force_close_attendance(): void
    {
        $period = Attendance::factory()->create(['company_id' => $this->company->id, 'user_id' => $this->conductor->id]);

        Sanctum::actingAs($this->conductor);

        $this->postJson("/api/company/attendance/{$period->id}/close", ['note' => 'x'])->assertForbidden();
    }

    public function test_attendance_is_company_scoped(): void
    {
        $otherCompany = Company::factory()->create();
        $otherPeriod = Attendance::factory()->create(['company_id' => $otherCompany->id]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/company/attendance')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/company/attendance/{$otherPeriod->id}/close", ['note' => 'x'])->assertNotFound();
    }
}
