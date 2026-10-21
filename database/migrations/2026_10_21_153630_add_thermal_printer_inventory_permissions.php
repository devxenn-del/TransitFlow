<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Thermal printers are now managed by the Super Admin (Thermal Printer
 * Management) and only viewed by companies. For databases seeded before
 * this — fresh installs get the same from RbacSeeder:
 * - adds the platform-only `printers.inventory.view` / `.manage` keys
 *   (group listed in Permission::PLATFORM_GROUPS) for the Super Admin role;
 * - deletes the company-side write keys `thermalprinters.create/.edit/
 *   .delete`. Their role, user and company grants cascade away with them,
 *   so no company role can keep write access. `thermalprinters.view` stays.
 */
return new class extends Migration
{
    /**
     * permission key => [name, nav label, nav url, nav icon].
     *
     * @var array<string, array{0: string, 1: ?string, 2: ?string, 3: ?string}>
     */
    private const PERMISSIONS = [
        'printers.inventory.view' => ['View Thermal Printer Inventory (all companies)', 'Thermal Printer Management', '/super-admin/thermal-printers', 'bi-printer'],
        'printers.inventory.manage' => ['Register / Edit / Assign / Change Status of Thermal Printers', null, null, null],
    ];

    private const GROUP = 'Thermal Printer Management';

    /**
     * The retired company-side write keys, with the role keys RbacSeeder
     * used to grant them (restored on rollback).
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const RETIRED = [
        'thermalprinters.create' => ['Add Thermal Printer', ['company_admin']],
        'thermalprinters.edit' => ['Edit / Assign Thermal Printer', ['company_admin', 'manager']],
        'thermalprinters.delete' => ['Delete Thermal Printer', ['company_admin']],
    ];

    public function up(): void
    {
        $now = now();

        DB::table('permission_groups')->insertOrIgnore([
            'name' => self::GROUP,
            'sort_order' => (int) DB::table('permission_groups')->max('sort_order') + 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $groupId = DB::table('permission_groups')->where('name', self::GROUP)->value('id');

        foreach (self::PERMISSIONS as $key => [$name, $navLabel, $navUrl, $navIcon]) {
            DB::table('permissions')->insertOrIgnore([
                'permission_group_id' => $groupId,
                'permission_key' => $key,
                'name' => $name,
                'nav_label' => $navLabel,
                'nav_url' => $navUrl,
                'nav_icon' => $navIcon,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $permissionId = DB::table('permissions')->where('permission_key', $key)->value('id');

            DB::table('role_permissions')->insertOrIgnore(DB::table('roles')->where('key', 'super_admin')->pluck('id')->map(fn (int $roleId) => [
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'allowed' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }

        DB::table('permissions')->whereIn('permission_key', array_keys(self::RETIRED))->delete();
    }

    public function down(): void
    {
        $now = now();
        $groupId = DB::table('permission_groups')->where('name', 'Thermal Printers')->value('id');

        foreach (self::RETIRED as $key => [$name, $roleKeys]) {
            DB::table('permissions')->insertOrIgnore([
                'permission_group_id' => $groupId,
                'permission_key' => $key,
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $permissionId = DB::table('permissions')->where('permission_key', $key)->value('id');
            $roleIds = DB::table('roles')->whereIn('key', [...$roleKeys, 'super_admin'])->pluck('id');

            DB::table('role_permissions')->insertOrIgnore($roleIds->map(fn (int $roleId) => [
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'allowed' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            DB::table('users')->whereIn('role_id', $roleIds)->pluck('id')->chunk(500)->each(
                fn ($userIds) => DB::table('user_permissions')->insertOrIgnore($userIds->map(fn (int $userId) => [
                    'user_id' => $userId,
                    'permission_id' => $permissionId,
                    'allowed' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all()),
            );
        }

        DB::table('permissions')->whereIn('permission_key', array_keys(self::PERMISSIONS))->delete();
        DB::table('permission_groups')->where('name', self::GROUP)->delete();
    }
};
