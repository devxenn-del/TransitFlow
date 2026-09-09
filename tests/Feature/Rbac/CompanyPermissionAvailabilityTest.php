<?php

namespace Tests\Feature\Rbac;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

/**
 * The Super Admin controls which permission keys a company may use at all;
 * a Company Admin can only assign what is left available.
 */
class CompanyPermissionAvailabilityTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private User $superAdmin;

    private User $companyAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->companyAdmin = User::factory()->companyAdmin($this->company)->create();
    }

    private function disable(array $keys, ?Company $company = null): void
    {
        Sanctum::actingAs($this->superAdmin);
        $this->putJson('/api/super-admin/companies/'.($company ?? $this->company)->id.'/permissions', ['disabled' => $keys])
            ->assertOk();
    }

    public function test_by_default_every_company_assignable_key_is_enabled(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $body = $this->getJson("/api/super-admin/companies/{$this->company->id}/permissions")
            ->assertOk()
            ->json('data');

        $this->assertSame([], $body['disabled']);
        $allEnabled = collect($body['groups'])->flatten(1)->every(fn ($p) => $p['enabled'] === true);
        $this->assertTrue($allEnabled);
    }

    public function test_disabling_a_key_revokes_it_live_from_a_company_user(): void
    {
        $office = User::factory()->forCompany($this->company)->withRole('office')->create();
        $this->assertTrue($office->fresh()->hasPermissionTo('fuel.view'));

        $this->disable(['fuel.view']);

        $office = $office->fresh();
        $this->assertFalse($office->hasPermissionTo('fuel.view'));

        Sanctum::actingAs($office);
        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonMissing(['fuel.view']);
        $this->getJson('/api/company/fuel')->assertForbidden();
    }

    public function test_another_company_is_unaffected(): void
    {
        $otherCompany = Company::factory()->create();
        $otherOffice = User::factory()->forCompany($otherCompany)->withRole('office')->create();

        $this->disable(['fuel.view']);

        $this->assertTrue($otherOffice->fresh()->hasPermissionTo('fuel.view'));
    }

    public function test_the_super_admin_is_never_affected(): void
    {
        $this->disable(['fuel.view', 'reports.view', 'buses.view']);

        $this->assertTrue($this->superAdmin->fresh()->hasPermissionTo('fuel.view'));
    }

    public function test_a_company_admin_cannot_grant_a_disabled_key_to_a_role(): void
    {
        $this->disable(['fuel.view']);

        Sanctum::actingAs($this->companyAdmin);

        $created = $this->postJson('/api/company/roles', [
            'name' => 'Depot Clerk',
            'permission_keys' => ['fuel.view', 'buses.view'],
        ])->assertCreated()->json('data');

        $keys = collect($created['default_permissions'] ?? []);
        $this->assertTrue($keys->contains('buses.view'));
        $this->assertFalse($keys->contains('fuel.view'));
    }

    public function test_a_company_admin_cannot_grant_a_disabled_key_to_a_user(): void
    {
        $this->disable(['fuel.view']);
        $user = User::factory()->forCompany($this->company)->withRole('office')->create();

        Sanctum::actingAs($this->companyAdmin);

        $granted = $this->putJson("/api/company/users/{$user->id}/permissions", [
            'permissions' => ['fuel.view', 'buses.view'],
        ])->assertOk()->json('granted');

        $this->assertContains('buses.view', $granted);
        $this->assertNotContains('fuel.view', $granted);
    }

    public function test_the_company_role_catalogue_marks_availability(): void
    {
        $this->disable(['fuel.view']);

        Sanctum::actingAs($this->companyAdmin);

        $body = $this->getJson('/api/company/roles')->assertOk()->json();

        $this->assertContains('fuel.view', $body['disabled_permissions']);
        $fuel = collect($body['catalogue'])->flatten(1)->firstWhere('key', 'fuel.view');
        $this->assertFalse($fuel['available']);
    }

    public function test_re_enabling_restores_a_previously_disabled_key(): void
    {
        $office = User::factory()->forCompany($this->company)->withRole('office')->create();

        $this->disable(['fuel.view']);
        $this->assertFalse($office->fresh()->hasPermissionTo('fuel.view'));

        $this->disable([]); // clear all overrides
        $this->assertTrue($office->fresh()->hasPermissionTo('fuel.view'));
    }

    public function test_the_availability_endpoint_ignores_platform_and_unknown_keys(): void
    {
        $this->disable(['companies.view', 'not.a.key', 'fuel.view']);

        Sanctum::actingAs($this->superAdmin);
        $disabled = $this->getJson("/api/super-admin/companies/{$this->company->id}/permissions")
            ->assertOk()->json('data.disabled');

        $this->assertSame(['fuel.view'], $disabled);
    }

    public function test_a_company_admin_cannot_reach_the_super_admin_availability_endpoint(): void
    {
        Sanctum::actingAs($this->companyAdmin);

        $this->getJson("/api/super-admin/companies/{$this->company->id}/permissions")->assertForbidden();
        $this->putJson("/api/super-admin/companies/{$this->company->id}/permissions", ['disabled' => []])->assertForbidden();
    }
}
