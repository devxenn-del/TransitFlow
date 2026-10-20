<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Fleet > Conductors permission keys (commit aa860d6) were only added to
 * RbacSeeder, which covers fresh installs and new companies — a database
 * seeded before that never got them, so its admins can't open Conductors.
 * Adds the keys and grants them to the roles — and, since `user_permissions`
 * is the live authorization source, the existing users of those roles —
 * exactly as RbacSeeder does. Idempotent (insertOrIgnore throughout).
 */
return new class extends Migration
{
    /**
     * permission key => [name, nav label, nav url, nav icon, role keys granted it by default].
     *
     * @var array<string, array{0: string, 1: ?string, 2: ?string, 3: ?string, 4: list<string>}>
     */
    private const PERMISSIONS = [
        'conductors.view' => ['View Conductors', 'Conductors', '/company/conductors', 'bi-person-vcard', ['company_admin', 'manager', 'chairman', 'office']],
        'conductors.create' => ['Add Conductor', null, null, null, ['company_admin']],
        'conductors.edit' => ['Edit Conductor (incl. assigned buses)', null, null, null, ['company_admin']],
        'conductors.delete' => ['Delete Conductor', null, null, null, ['company_admin']],
    ];

    private const GROUP = 'Conductors';

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

        foreach (self::PERMISSIONS as $key => [$name, $navLabel, $navUrl, $navIcon, $roleKeys]) {
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
    }
};
