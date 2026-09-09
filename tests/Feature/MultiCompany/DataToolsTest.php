<?php

namespace Tests\Feature\MultiCompany;

use App\Models\Attendance;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Dispatch;
use App\Models\FuelRecord;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class DataToolsTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private User $admin;

    private Bus $bus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create(['code' => 'PERJODA']);
        $this->admin = User::factory()->companyAdmin($this->company)->create();
        $this->bus = Bus::factory()->for($this->company)->create();
    }

    private function seedTransactional(?Company $company = null): Trip
    {
        $company ??= $this->company;
        $trip = Trip::factory()->for($company)->create(['bus_id' => Bus::factory()->for($company)->create()->id]);
        Ticket::factory()->for($company)->count(3)->create(['trip_id' => $trip->id]);
        Dispatch::factory()->forTrip($trip)->create();
        FuelRecord::factory()->for($company)->create(['bus_id' => $trip->bus_id]);
        Attendance::factory()->create(['company_id' => $company->id, 'user_id' => $this->admin->id]);

        return $trip;
    }

    public function test_clean_preview_counts_the_transactional_rows_without_deleting(): void
    {
        $this->seedTransactional();

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/company/data-tools/clean-preview')
            ->assertOk()
            ->assertJsonPath('data.confirm_with', 'PERJODA')
            ->assertJsonPath('data.counts.tickets', 3)
            ->assertJsonPath('data.counts.trips', 1);

        $this->assertDatabaseCount('tickets', 3);
    }

    public function test_clean_requires_the_exact_company_code_as_confirmation(): void
    {
        $this->seedTransactional();

        Sanctum::actingAs($this->admin);

        $this->postJson('/api/company/data-tools/clean', ['confirm' => 'perjoda'])->assertStatus(422);
        $this->postJson('/api/company/data-tools/clean', ['confirm' => ''])->assertStatus(422);

        $this->assertDatabaseCount('tickets', 3);
    }

    public function test_clean_wipes_transactional_data_keeps_master_data_and_writes_an_audit_row(): void
    {
        $this->seedTransactional();

        Sanctum::actingAs($this->admin);

        $this->postJson('/api/company/data-tools/clean', ['confirm' => 'PERJODA'])
            ->assertOk()
            ->assertJsonPath('data.deleted.tickets', 3)
            ->assertJsonPath('data.deleted.trips', 1);

        // transactional gone
        $this->assertDatabaseCount('trips', 0);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('dispatches', 0);
        $this->assertDatabaseCount('fuel_records', 0);
        $this->assertDatabaseCount('conductor_attendance', 0);

        // master + the company + its users kept
        $this->assertDatabaseHas('companies', ['id' => $this->company->id]);
        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
        $this->assertTrue(Bus::query()->where('company_id', $this->company->id)->exists());

        // audit
        $this->assertDatabaseHas('data_operations', [
            'company_id' => $this->company->id,
            'type' => 'clean',
            'performed_by' => $this->admin->id,
        ]);
    }

    public function test_clean_only_touches_the_callers_company(): void
    {
        $this->seedTransactional();
        $other = Company::factory()->create(['code' => 'SOUTH']);
        $this->seedTransactional($other);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/company/data-tools/clean', ['confirm' => 'PERJODA'])->assertOk();

        $this->assertTrue(Trip::withoutGlobalScopes()->where('company_id', $other->id)->exists());
        $this->assertSame(3, Ticket::withoutGlobalScopes()->where('company_id', $other->id)->count());
    }

    public function test_export_streams_a_json_file_of_the_companys_rows(): void
    {
        $this->seedTransactional();

        Sanctum::actingAs($this->admin);

        $response = $this->get('/api/company/data-tools/export')
            ->assertOk()
            ->assertHeader('content-type', 'application/json');

        $payload = json_decode($response->streamedContent(), true);
        $this->assertSame('PERJODA', $payload['company']['code']);
        $this->assertCount(3, $payload['tables']['tickets']);
        $this->assertCount(1, $payload['tables']['trips']);
        $this->assertArrayHasKey('buses', $payload['tables']);

        $this->assertDatabaseHas('data_operations', ['company_id' => $this->company->id, 'type' => 'export']);
    }

    public function test_history_lists_recent_operations_for_the_company(): void
    {
        Sanctum::actingAs($this->admin);
        $this->get('/api/company/data-tools/export');
        $this->postJson('/api/company/data-tools/clean', ['confirm' => 'PERJODA'])->assertOk();

        $this->getJson('/api/company/data-tools/history')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'clean');
    }

    public function test_data_tools_are_permission_gated(): void
    {
        $office = User::factory()->forCompany($this->company)->withRole('office')->create();

        Sanctum::actingAs($office);
        $this->getJson('/api/company/data-tools/clean-preview')->assertForbidden();
        $this->postJson('/api/company/data-tools/clean', ['confirm' => 'PERJODA'])->assertForbidden();
        $this->get('/api/company/data-tools/export')->assertForbidden();
    }
}
