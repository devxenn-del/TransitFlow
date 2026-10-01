<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the Company Documents permission keys and grants them to the roles
 * (and, since `user_permissions` is the live authorization source, the
 * existing users of those roles) that should have them out of the box —
 * RbacSeeder only covers fresh installs and new companies.
 */
return new class extends Migration
{
    /**
     * permission key => role keys granted it by default.
     *
     * @var array<string, list<string>>
     */
    private const GRANTS = [
        'documents.view' => ['company_admin', 'manager', 'chairman', 'office'],
        'documents.manage' => ['company_admin'],
    ];

    private const NAMES = [
        'documents.view' => 'View / Download Company Documents',
        'documents.manage' => 'Upload / Replace / Delete Company Documents',
    ];

    public function up(): void
    {
        $now = now();

        DB::table('permission_groups')->insertOrIgnore([
            'name' => 'Documents',
            'sort_order' => (int) DB::table('permission_groups')->max('sort_order') + 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $groupId = DB::table('permission_groups')->where('name', 'Documents')->value('id');

        foreach (self::GRANTS as $key => $roleKeys) {
            DB::table('permissions')->insertOrIgnore([
                'permission_group_id' => $groupId,
                'permission_key' => $key,
                'name' => self::NAMES[$key],
                'nav_label' => $key === 'documents.view' ? 'Documents' : null,
                'nav_url' => $key === 'documents.view' ? '/company/documents' : null,
                'nav_icon' => $key === 'documents.view' ? 'bi-folder2-open' : null,
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
        DB::table('permissions')->whereIn('permission_key', array_keys(self::GRANTS))->delete();
        DB::table('permission_groups')->where('name', 'Documents')->delete();
    }
};
