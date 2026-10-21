<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

/**
 * Moving existing (company-owned, conductor-assigned) printers into the
 * platform inventory — and back again on rollback.
 */
class ThermalPrinterInventoryMigrationTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private const REWORK = '2026_10_21_153629_rework_thermal_printers_as_platform_inventory.php';

    private const PERMISSIONS = '2026_10_21_153630_add_thermal_printer_inventory_permissions.php';

    public function test_existing_printers_get_generated_ids_and_keep_their_data(): void
    {
        $migration = require database_path('migrations/'.self::REWORK);
        $migration->down();

        $company = Company::factory()->create();
        $conductor = User::factory()->forCompany($company)->withRole('conductor')->create();
        DB::table('thermal_printers')->insert([
            ['company_id' => $company->id, 'device_id' => 'TP-001', 'mac_address' => '00-1a-2b-3c-4d-5e', 'model' => 'Epson', 'status' => 'Active', 'created_at' => '2026-09-10 08:00:00', 'updated_at' => now()],
            ['company_id' => $company->id, 'device_id' => 'TP-002', 'mac_address' => 'garbage', 'model' => 'Star', 'status' => 'Inactive', 'created_at' => '2026-10-01 08:00:00', 'updated_at' => now()],
        ]);
        $firstId = DB::table('thermal_printers')->where('device_id', 'TP-001')->value('id');
        DB::table('users')->where('id', $conductor->id)->update(['thermal_printer_id' => $firstId]);

        $migration->up();

        $first = DB::table('thermal_printers')->where('serial_number', 'TP-001')->first();
        $this->assertSame('TFTP-100926-000001', $first->thermal_id);
        $this->assertSame('TFD-100926-000001', $first->device_id);
        $this->assertSame('00:1A:2B:3C:4D:5E', $first->mac_address);
        $this->assertSame('Assigned', $first->status);
        $this->assertSame($company->id, (int) $first->company_id);

        $second = DB::table('thermal_printers')->where('serial_number', 'TP-002')->first();
        $this->assertSame('TFTP-011026-000002', $second->thermal_id);
        $this->assertSame('Under Maintenance', $second->status);
        $this->assertNull($second->mac_address);
        $this->assertStringContainsString('garbage', $second->remarks);

        $this->assertNull($conductor->fresh()->thermal_printer_id);
        $this->assertDatabaseHas('thermal_printer_assignments', ['thermal_printer_id' => $firstId, 'action' => 'conductor_unlinked']);

        // The next registration continues the sequence.
        $this->assertSame(2, DB::table('id_sequences')->where('name', 'thermal_printers')->value('last_value'));
    }

    public function test_company_write_keys_are_removed_and_inventory_keys_added(): void
    {
        $company = Company::factory()->create();
        $migration = require database_path('migrations/'.self::PERMISSIONS);

        // A database from before the change: company admins could add / edit / delete printers.
        $migration->down();
        $admin = User::factory()->companyAdmin($company)->create();
        $this->assertTrue($admin->hasPermissionTo('thermalprinters.edit'));

        $migration->up();
        $migration->up(); // safe to re-run

        $admin = $admin->fresh();
        $this->assertTrue($admin->hasPermissionTo('thermalprinters.view'));
        foreach (['thermalprinters.create', 'thermalprinters.edit', 'thermalprinters.delete'] as $key) {
            $this->assertFalse($admin->hasPermissionTo($key), "{$key} should be gone");
        }
        $this->assertDatabaseHas('permissions', ['permission_key' => 'printers.inventory.view', 'nav_url' => '/super-admin/thermal-printers']);
        $this->assertTrue(DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('roles.key', 'super_admin')
            ->where('permissions.permission_key', 'printers.inventory.manage')
            ->exists());
    }
}
