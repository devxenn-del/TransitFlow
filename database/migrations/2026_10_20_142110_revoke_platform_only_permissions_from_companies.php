<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mobile App, Data Tools and the Audit Log are now Super Admin only
 * (Permission::PLATFORM_GROUPS). Strips those keys from every company role,
 * the non-platform role templates and — since `user_permissions` is the
 * live authorization source — every company user. RbacSeeder only covers
 * fresh installs and new companies.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const KEYS = ['mobileapp.view', 'mobileapp.manage', 'backup.view', 'backup.download', 'cleandata.run', 'audit.view'];

    public function up(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('permission_key', self::KEYS)->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        $roleIds = DB::table('roles')
            ->where(fn ($q) => $q->whereNotNull('company_id')->orWhere('key', '!=', 'super_admin'))
            ->pluck('id');

        DB::table('role_permissions')
            ->whereIn('role_id', $roleIds)
            ->whereIn('permission_id', $permissionIds)
            ->delete();

        DB::table('user_permissions')
            ->whereIn('permission_id', $permissionIds)
            ->whereIn('user_id', DB::table('users')->whereNotNull('company_id')->select('id'))
            ->delete();
    }

    public function down(): void
    {
        //
    }
};
