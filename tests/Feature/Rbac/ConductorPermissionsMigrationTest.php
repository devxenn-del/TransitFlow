<?php

namespace Tests\Feature\Rbac;

use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class ConductorPermissionsMigrationTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private const KEYS = ['conductors.view', 'conductors.create', 'conductors.edit', 'conductors.delete'];

    public function test_it_grants_conductor_permissions_to_a_database_seeded_before_they_existed(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->forCompany($company)->withRole('company_admin')->create();
        $office = User::factory()->forCompany($company)->withRole('office')->create();

        // A database seeded before the Conductors keys existed.
        Permission::query()->whereIn('permission_key', self::KEYS)->delete();
        $this->assertFalse($admin->fresh()->hasPermissionTo('conductors.view'));

        $migration = require database_path('migrations/2026_10_20_160000_add_conductor_permissions.php');
        $migration->up();
        $migration->up(); // safe to re-run

        $admin = $admin->fresh();
        foreach (self::KEYS as $key) {
            $this->assertTrue($admin->hasPermissionTo($key), "company_admin should have {$key}");
        }

        $office = $office->fresh();
        $this->assertTrue($office->hasPermissionTo('conductors.view'));
        $this->assertFalse($office->hasPermissionTo('conductors.edit'));

        $this->assertSame(4, DB::table('permissions')->whereIn('permission_key', self::KEYS)->count());
    }
}
