<?php

namespace Tests\Feature\MultiCompany;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->companyAdmin($this->company)->create();
    }

    public function test_updating_company_settings_records_the_changed_columns(): void
    {
        CompanySetting::factory()->for($this->company)->create();

        Sanctum::actingAs($this->admin);
        $this->putJson('/api/company/settings', ['ticket_footer' => 'Thank you!'])->assertOk();

        $log = AuditLog::query()->where('action', 'company_setting.updated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->company->id, $log->company_id);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertContains('ticket_footer', $log->context['changed']);
    }

    public function test_creating_a_role_is_audited(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/company/roles', ['name' => 'Depot Clerk', 'permission_keys' => ['buses.view']])
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $this->company->id,
            'action' => 'role.created',
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_approving_a_remittance_is_audited_with_the_variance(): void
    {
        $manager = User::factory()->forCompany($this->company)->withRole('manager')->create();
        $trip = Trip::factory()->for($this->company)->create([
            'status' => 'Arrived',
            'ended_at' => now(),
            'remitted_amount' => 500,
            'remittance_received_at' => now(),
        ]);
        $trip->remittanceCashCount()->create([
            'company_id' => $this->company->id,
            'op_date' => now()->toDateString(),
            'shift' => 'Morning',
            'counted_total' => 480,
            'expected_amount' => 500,
            'status' => 'Received',
            'received_by' => $manager->id,
            'received_at' => now(),
        ]);

        Sanctum::actingAs($manager);
        $this->postJson("/api/company/remittances/{$trip->id}/approve")->assertOk();

        $log = AuditLog::query()->where('action', 'remittance.approved')->first();
        $this->assertNotNull($log);
        $this->assertEquals(20, $log->context['short']);
    }

    public function test_super_admin_actions_are_audited_against_the_target_company(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        Sanctum::actingAs($superAdmin);
        $this->patchJson("/api/super-admin/companies/{$this->company->id}/status", ['status' => 'suspended'])
            ->assertOk();
        $this->putJson("/api/super-admin/companies/{$this->company->id}/permissions", ['disabled' => ['fuel.view']])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->company->id, 'action' => 'company.status_changed']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->company->id, 'action' => 'company.feature_access.updated']);
    }

    public function test_the_company_feed_is_scoped_filterable_and_permission_gated(): void
    {
        AuditLog::factory()->for($this->company)->create(['action' => 'role.created']);
        AuditLog::factory()->for($this->company)->create(['action' => 'company_setting.updated']);
        $other = Company::factory()->create();
        AuditLog::factory()->for($other)->create(['action' => 'role.created']);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/company/audit-log')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/company/audit-log?action=role.')->assertOk()->assertJsonCount(1, 'data');

        // office has no audit.view
        Sanctum::actingAs(User::factory()->forCompany($this->company)->withRole('office')->create());
        $this->getJson('/api/company/audit-log')->assertForbidden();
    }

    public function test_the_platform_feed_sees_every_company_and_rejects_a_company_admin(): void
    {
        AuditLog::factory()->for($this->company)->create();
        AuditLog::factory()->for(Company::factory()->create())->create();
        AuditLog::factory()->create(['company_id' => null, 'action' => 'company.provisioned']);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/super-admin/audit-log')->assertForbidden();

        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->getJson('/api/super-admin/audit-log')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/super-admin/audit-log?platform_only=1')->assertOk()->assertJsonCount(1, 'data');
    }
}
