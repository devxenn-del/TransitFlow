<?php

namespace Tests\Feature\MultiCompany;

use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

/**
 * The company workspace (`/companies/{company}` in the SPA): who may open
 * which company, and that the shared `company/*` endpoints act on the
 * selected company for a Super Admin while never letting a company user
 * reach another company — even by editing the URL or the X-Company-Id header.
 */
class CompanyWorkspaceTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $companyA;

    private Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyA = Company::factory()->create(['name' => 'Camia Transport']);
        $this->companyB = Company::factory()->create(['name' => 'Other Lines']);
    }

    public function test_super_admin_can_open_any_company_workspace_with_its_own_counts(): void
    {
        Bus::factory()->count(2)->create(['company_id' => $this->companyA->id]);
        Driver::factory()->create(['company_id' => $this->companyA->id]);
        Bus::factory()->count(5)->create(['company_id' => $this->companyB->id]);
        User::factory()->forCompany($this->companyA)->withRole('conductor')->create();

        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->getJson("/api/companies/{$this->companyA->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Camia Transport')
            ->assertJsonPath('stats.buses', 2)
            ->assertJsonPath('stats.drivers', 1)
            ->assertJsonPath('stats.conductors', 1)
            ->assertJsonCount(2, 'recent.buses');
    }

    public function test_company_admin_can_open_their_own_workspace_but_not_another_companys(): void
    {
        Sanctum::actingAs(User::factory()->companyAdmin($this->companyA)->create());

        $this->getJson("/api/companies/{$this->companyA->id}")->assertOk();
        $this->getJson("/api/companies/{$this->companyB->id}")->assertForbidden();
    }

    public function test_a_conductor_cannot_open_the_company_workspace(): void
    {
        Sanctum::actingAs(User::factory()->forCompany($this->companyA)->withRole('conductor')->create());

        $this->getJson("/api/companies/{$this->companyA->id}")->assertForbidden();
    }

    public function test_recent_lists_are_omitted_for_modules_the_caller_cannot_see(): void
    {
        // Office role: may view buses but not the audit log. Grant it the
        // workspace itself (company.profile.view) on top.
        $office = User::factory()->forCompany($this->companyA)->create();
        $office->permissions()->syncWithoutDetaching([
            Permission::query()->where('permission_key', 'company.profile.view')->value('id') => ['allowed' => true],
        ]);
        Sanctum::actingAs($office);

        $this->getJson("/api/companies/{$this->companyA->id}")
            ->assertOk()
            ->assertJsonPath('recent.activity', null)
            ->assertJsonIsArray('recent.buses');
    }

    public function test_super_admin_lists_and_creates_records_inside_the_selected_company(): void
    {
        Bus::factory()->create(['company_id' => $this->companyA->id, 'bus_number' => 'A-1']);
        Bus::factory()->create(['company_id' => $this->companyB->id, 'bus_number' => 'B-1']);

        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $headers = ['X-Company-Id' => $this->companyA->id];

        $this->getJson('/api/company/buses', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.bus_number', 'A-1');

        $this->postJson('/api/company/buses', ['bus_number' => 'A-2', 'plate_number' => 'AAA-1234'], $headers)
            ->assertCreated()
            ->assertJsonPath('data.company_id', $this->companyA->id);
    }

    public function test_super_admin_must_select_a_company_to_use_company_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->getJson('/api/company/buses')->assertUnprocessable();
        $this->postJson('/api/company/buses', ['bus_number' => 'X-1', 'plate_number' => 'XXX-1'])->assertUnprocessable();
        $this->assertDatabaseMissing('buses', ['bus_number' => 'X-1']);
    }

    public function test_company_user_naming_another_company_in_the_header_is_refused(): void
    {
        Bus::factory()->create(['company_id' => $this->companyB->id]);
        Sanctum::actingAs(User::factory()->companyAdmin($this->companyA)->create());

        $this->getJson('/api/company/buses', ['X-Company-Id' => $this->companyB->id])->assertForbidden();
        $this->getJson('/api/company/buses', ['X-Company-Id' => $this->companyA->id])->assertOk();
    }

    public function test_super_admin_can_create_a_conductor_and_edit_the_profile_of_the_selected_company(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $headers = ['X-Company-Id' => $this->companyA->id];

        $this->postJson('/api/company/conductors', [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@camia.test',
            'password' => 'secret-password',
        ], $headers)->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'juan@camia.test', 'company_id' => $this->companyA->id]);

        $this->putJson('/api/company/profile', ['name' => 'Camia Transport Cooperative'], $headers)->assertOk();
        $this->assertSame('Camia Transport Cooperative', $this->companyA->fresh()->name);
        $this->assertSame('Other Lines', $this->companyB->fresh()->name);
    }

    public function test_company_list_includes_fleet_counts(): void
    {
        Bus::factory()->count(3)->create(['company_id' => $this->companyA->id]);
        Driver::factory()->count(2)->create(['company_id' => $this->companyA->id]);
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $row = collect($this->getJson('/api/super-admin/companies')->assertOk()->json('data'))
            ->firstWhere('id', $this->companyA->id);

        $this->assertSame(3, $row['buses_count']);
        $this->assertSame(2, $row['drivers_count']);
        $this->assertSame(0, $row['conductors_count']);
    }

    public function test_login_records_last_login_time(): void
    {
        $user = User::factory()->companyAdmin($this->companyA)->create(['password' => 'secret-password']);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-password'])->assertOk();

        $this->assertNotNull($user->fresh()->last_login_at);
    }
}
