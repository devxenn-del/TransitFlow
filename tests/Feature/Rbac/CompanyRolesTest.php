<?php

namespace Tests\Feature\Rbac;

use App\Actions\ProvisionCompany;
use App\Actions\SyncUserRolePermissions;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class CompanyRolesTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->forCompany($this->company)->withRole('company_admin')->create();
    }

    public function test_provisioning_a_company_clones_the_role_templates_with_their_grants(): void
    {
        $company = app(ProvisionCompany::class)->handle(['name' => 'Fresh Lines Co']);

        $keys = Role::query()->forCompany($company->id)->pluck('key')->sort()->values()->all();
        $this->assertSame(['chairman', 'company_admin', 'conductor', 'manager', 'office'], $keys);

        $conductor = Role::query()->forCompany($company->id)->where('key', 'conductor')->firstOrFail();
        $this->assertTrue($conductor->grantedPermissionKeys()->contains('tickets.issue'));
        $this->assertFalse(Role::query()->forCompany($company->id)->where('key', 'company_admin')->firstOrFail()->is_platform);
        $this->assertTrue(Role::query()->forCompany($company->id)->where('key', 'company_admin')->firstOrFail()->is_admin);
    }

    public function test_index_returns_only_this_companys_roles_plus_the_assignable_catalogue(): void
    {
        $other = Company::factory()->create();
        Role::query()->create(['company_id' => $other->id, 'key' => 'secret', 'name' => 'Secret', 'is_platform' => false, 'sort_order' => 99]);

        Sanctum::actingAs($this->admin);

        $res = $this->getJson('/api/company/roles')->assertOk();
        $names = collect($res->json('data'))->pluck('key');

        $this->assertTrue($names->contains('conductor'));
        $this->assertFalse($names->contains('secret'));
        $this->assertFalse($names->contains('super_admin'));
        // Platform-only groups are not offered to company roles.
        $this->assertArrayNotHasKey('Companies', $res->json('catalogue'));
        $this->assertArrayHasKey('Terminals', $res->json('catalogue'));
    }

    public function test_admin_creates_a_custom_role_with_a_slugged_key_and_grants(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/company/roles', [
            'name' => 'Terminal Supervisor',
            'description' => 'Watches the yard',
            'permission_keys' => ['terminals.view', 'tripmonitoring.view'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.key', 'terminal_supervisor')
            ->assertJsonPath('data.is_admin', false)
            ->assertJsonPath('data.default_permissions', ['terminals.view', 'tripmonitoring.view']);

        $this->assertDatabaseHas('roles', ['company_id' => $this->company->id, 'name' => 'Terminal Supervisor']);
    }

    public function test_a_company_role_cannot_be_granted_platform_permissions(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/company/roles', ['name' => 'Sneaky', 'permission_keys' => ['companies.view']])
            ->assertJsonValidationErrorFor('permission_keys.0');
    }

    public function test_editing_a_role_updates_grants_and_can_reapply_to_existing_users(): void
    {
        $role = Role::query()->forCompany($this->company->id)->where('key', 'office')->firstOrFail();
        $user = User::factory()->forCompany($this->company)->create();
        $user->forceFill(['role_id' => $role->id])->save();
        app(SyncUserRolePermissions::class)->handle($user->refresh(), reset: true);
        $this->assertFalse($user->hasPermissionTo('drivers.create'));

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/company/roles/{$role->id}", [
            'permission_keys' => ['drivers.view', 'drivers.create'],
            'apply_to_users' => true,
        ])->assertOk();

        $this->assertTrue($user->fresh()->hasPermissionTo('drivers.create'));
    }

    public function test_a_role_with_users_or_the_admin_role_cannot_be_deleted(): void
    {
        Sanctum::actingAs($this->admin);
        $adminRole = Role::query()->forCompany($this->company->id)->where('is_admin', true)->firstOrFail();
        $this->deleteJson("/api/company/roles/{$adminRole->id}")->assertJsonValidationErrorFor('role');

        $office = Role::query()->forCompany($this->company->id)->where('key', 'office')->firstOrFail();
        User::factory()->forCompany($this->company)->create()->forceFill(['role_id' => $office->id])->save();
        $this->deleteJson("/api/company/roles/{$office->id}")->assertJsonValidationErrorFor('role');

        $chairman = Role::query()->forCompany($this->company->id)->where('key', 'chairman')->firstOrFail();
        $this->deleteJson("/api/company/roles/{$chairman->id}")->assertNoContent();
        $this->assertDatabaseMissing('roles', ['id' => $chairman->id]);
    }

    public function test_roles_are_company_scoped_end_to_end(): void
    {
        $other = Company::factory()->create();
        $otherRole = Role::query()->forCompany($other->id)->where('key', 'manager')->firstOrFail();

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/company/roles/{$otherRole->id}")->assertNotFound();
        $this->putJson("/api/company/roles/{$otherRole->id}", ['name' => 'Hijacked'])->assertNotFound();

        // A user cannot be created against another company's role.
        $this->postJson('/api/company/users', [
            'name' => 'X', 'email' => 'x@x.test', 'password' => 'password123', 'role_id' => $otherRole->id,
        ])->assertJsonValidationErrorFor('role_id');
    }

    public function test_non_managers_cannot_manage_roles(): void
    {
        Sanctum::actingAs(User::factory()->forCompany($this->company)->withRole('office')->create());

        $this->getJson('/api/company/roles')->assertForbidden();
        $this->postJson('/api/company/roles', ['name' => 'Nope'])->assertForbidden();
    }
}
