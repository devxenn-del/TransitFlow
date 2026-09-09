<?php

namespace Tests\Feature\Rbac;

use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class PermissionEnforcementTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    public function test_a_route_is_blocked_without_the_required_permission(): void
    {
        $company = Company::factory()->create();
        // office role grants buses.view / accounts.view but NOT buses.create.
        Sanctum::actingAs(User::factory()->forCompany($company)->create());

        $this->getJson('/api/company/buses')->assertOk();
        $this->postJson('/api/company/buses', ['bus_number' => 'X-1', 'plate_number' => 'X-0001'])
            ->assertForbidden();
    }

    public function test_a_user_with_no_grants_is_blocked_everywhere(): void
    {
        $company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->forCompany($company)->withoutPermissions()->create());

        $this->getJson('/api/company/buses')->assertForbidden();
        $this->getJson('/api/company/users')->assertForbidden();
    }

    public function test_super_admin_bypasses_every_permission_check(): void
    {
        Company::factory()->create();
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->getJson('/api/super-admin/companies')->assertOk();
        $this->getJson('/api/super-admin/users')->assertOk();
    }

    public function test_granting_a_permission_takes_effect_on_the_next_request(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->forCompany($company)->withoutPermissions()->create();

        Sanctum::actingAs($user);
        $this->getJson('/api/company/buses')->assertForbidden();

        $user->permissions()->attach(
            Permission::query()->where('permission_key', 'buses.view')->value('id'),
            ['allowed' => true],
        );

        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/company/buses')->assertOk();
    }

    public function test_has_permission_to_reflects_user_permission_rows(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->forCompany($company)->create();

        $this->assertTrue($user->hasPermissionTo('buses.view'));
        $this->assertFalse($user->hasPermissionTo('buses.delete'));
    }
}
