<?php

namespace Tests\Feature\Rbac;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private function officeRoleId(): int
    {
        return Role::query()->where('key', 'office')->value('id');
    }

    public function test_a_company_admin_creates_a_user_scoped_to_their_company_with_role_default_grants(): void
    {
        $company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->companyAdmin($company)->create());

        $response = $this->postJson('/api/company/users', [
            'name' => 'New Clerk',
            'email' => 'clerk@acme.test',
            'password' => 'secret-password',
            'role_id' => $this->officeRoleId(),
        ]);

        $response->assertCreated()->assertJsonPath('data.role', UserRole::CompanyUser->value);

        $created = User::query()->where('email', 'clerk@acme.test')->firstOrFail();
        $this->assertSame($company->id, $created->company_id);
        $this->assertTrue($created->hasPermissionTo('buses.view'));   // office default
        $this->assertFalse($created->hasPermissionTo('buses.delete'));
    }

    public function test_a_company_admin_cannot_create_accounts_when_the_platform_disables_it(): void
    {
        $company = Company::factory()->cannotCreateAccounts()->create();
        Sanctum::actingAs(User::factory()->companyAdmin($company)->create());

        $this->postJson('/api/company/users', [
            'name' => 'Blocked', 'email' => 'blocked@acme.test', 'password' => 'secret-password',
            'role_id' => $this->officeRoleId(),
        ])->assertForbidden();

        // Editing an existing account still works.
        $staff = User::factory()->forCompany($company)->create();
        $this->putJson("/api/company/users/{$staff->id}", ['name' => 'Renamed'])->assertOk();
    }

    public function test_the_super_admin_can_still_create_accounts_for_a_locked_company(): void
    {
        $company = Company::factory()->cannotCreateAccounts()->create();
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson('/api/super-admin/users', [
            'name' => 'Platform-made', 'email' => 'made@acme.test', 'password' => 'secret-password',
            'company_id' => $company->id,
            'role_id' => Role::query()->forCompany($company->id)->where('key', 'office')->value('id'),
        ])->assertCreated();
    }

    public function test_a_company_admin_cannot_create_a_super_admin(): void
    {
        Sanctum::actingAs(User::factory()->companyAdmin()->create());
        $platformRoleId = Role::query()->where('key', 'super_admin')->value('id');

        $this->postJson('/api/company/users', [
            'name' => 'Sneaky', 'email' => 'sneaky@acme.test', 'password' => 'secret-password',
            'role_id' => $platformRoleId,
        ])->assertJsonValidationErrorFor('role_id');
    }

    public function test_a_company_admin_only_sees_and_reaches_their_own_companys_users(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $bStaff = User::factory()->forCompany($b)->create();

        Sanctum::actingAs(User::factory()->companyAdmin($a)->create());

        $list = $this->getJson('/api/company/users')->assertOk();
        $this->assertFalse(collect($list->json('data'))->pluck('id')->contains($bStaff->id));

        $this->getJson("/api/company/users/{$bStaff->id}")->assertNotFound();
        $this->deleteJson("/api/company/users/{$bStaff->id}")->assertNotFound();
    }

    public function test_a_company_admin_cannot_delete_themselves(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin($company)->create();
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/company/users/{$admin->id}")->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_a_plain_user_cannot_manage_users(): void
    {
        $company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->forCompany($company)->create()); // office: no accounts.create

        $this->postJson('/api/company/users', [
            'name' => 'X', 'email' => 'x@acme.test', 'password' => 'secret-password',
            'role_id' => $this->officeRoleId(),
        ])->assertForbidden();
    }

    public function test_super_admin_creates_a_company_admin_for_any_company(): void
    {
        $company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $response = $this->postJson('/api/super-admin/users', [
            'name' => 'Assigned Admin',
            'email' => 'assigned@acme.test',
            'password' => 'secret-password',
            'role_id' => Role::query()->where('key', 'company_admin')->value('id'),
            'company_id' => $company->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', [
            'email' => 'assigned@acme.test',
            'company_id' => $company->id,
            'role' => UserRole::CompanyAdmin->value,
        ]);
    }

    public function test_company_admin_syncs_a_users_permission_grants(): void
    {
        $company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->companyAdmin($company)->create());
        $target = User::factory()->forCompany($company)->create();

        $this->putJson("/api/company/users/{$target->id}/permissions", [
            'permissions' => ['buses.view', 'buses.edit'],
        ])->assertOk()
            ->assertJsonPath('granted', ['buses.view', 'buses.edit']);

        $this->assertTrue($target->fresh()->hasPermissionTo('buses.edit'));
        $this->assertFalse($target->fresh()->hasPermissionTo('accounts.view'));
    }

    public function test_a_company_admin_cannot_grant_a_platform_permission(): void
    {
        $company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->companyAdmin($company)->create());
        $target = User::factory()->forCompany($company)->create();

        $this->putJson("/api/company/users/{$target->id}/permissions", [
            'permissions' => ['companies.view'],
        ])->assertJsonValidationErrorFor('permissions.0');
    }

    public function test_resetting_permissions_restores_role_defaults(): void
    {
        $company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->companyAdmin($company)->create());
        $target = User::factory()->forCompany($company)->create();

        $target->permissions()->sync([]); // strip everything

        $this->postJson("/api/company/users/{$target->id}/permissions/reset")->assertOk();

        // Reset === exactly the office role's defaults.
        $expected = Role::query()->where('key', 'office')->firstOrFail()->grantedPermissionKeys()->all();
        $this->assertEqualsCanonicalizing(
            $expected,
            $target->fresh()->grantedPermissionKeys()->all(),
        );
    }
}
