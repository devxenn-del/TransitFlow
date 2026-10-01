<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the fee-management (platform, Super Admin) and company billing
 * permission keys, and grants them to the roles — and, since
 * `user_permissions` is the live authorization source, the existing users
 * of those roles — that should have them out of the box. RbacSeeder only
 * covers fresh installs and new companies.
 */
return new class extends Migration
{
    /**
     * permission key => [group, name, nav label, nav url, nav icon, role keys granted it by default].
     *
     * @var array<string, array{0: string, 1: string, 2: ?string, 3: ?string, 4: ?string, 5: list<string>}>
     */
    private const PERMISSIONS = [
        'fees.view' => ['Fee Management', 'View Fees & Company Pricing', 'Fee Management', '/super-admin/fees', 'bi-cash-stack', []],
        'fees.manage' => ['Fee Management', 'Create / Edit Fees, Assign Standard & Special Pricing, Generate Billing', null, null, null, []],
        'billing.view' => ['Billing', 'View Own Company Fees & Billing Statements', 'Billing & Fees', '/company/billing', 'bi-receipt', ['company_admin']],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::PERMISSIONS as $key => [$group, $name, $navLabel, $navUrl, $navIcon, $roleKeys]) {
            DB::table('permission_groups')->insertOrIgnore([
                'name' => $group,
                'sort_order' => (int) DB::table('permission_groups')->max('sort_order') + 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $groupId = DB::table('permission_groups')->where('name', $group)->value('id');

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
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('permission_key', array_keys(self::PERMISSIONS))->delete();
        DB::table('permission_groups')->whereIn('name', ['Fee Management', 'Billing'])->delete();
    }
};
