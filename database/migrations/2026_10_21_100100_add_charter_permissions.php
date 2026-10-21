<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Charter Bookings permission keys (App\Http\Controllers\Api\Company\CharterBookingController)
 * for databases seeded before them — fresh installs get them from RbacSeeder.
 * Adds the keys and grants them to the roles — and, since `user_permissions`
 * is the live authorization source, the existing users of those roles —
 * exactly as RbacSeeder does. Idempotent (insertOrIgnore throughout).
 * Any other role (e.g. an "authorized dispatcher") can be granted them later
 * in Roles & Permissions.
 */
return new class extends Migration
{
    /**
     * permission key => [name, nav label, nav url, nav icon, role keys granted it by default].
     *
     * @var array<string, array{0: string, 1: ?string, 2: ?string, 3: ?string, 4: list<string>}>
     */
    private const PERMISSIONS = [
        'charters.view' => ['View Charter Bookings', 'Charter Bookings', '/company/charters', 'bi-calendar2-check', ['company_admin', 'manager', 'chairman', 'office']],
        'charters.create' => ['Create Charter Booking', null, null, null, ['company_admin', 'manager', 'office']],
        'charters.edit' => ['Edit / Cancel Charter Booking', null, null, null, ['company_admin', 'manager', 'office']],
        'charters.approve' => ['Approve / Reject Charter Booking', null, null, null, ['company_admin', 'manager']],
        'charters.payments' => ['Record Charter Payments, Charges, Expenses & Settlement', null, null, null, ['company_admin', 'manager', 'office']],
    ];

    private const GROUP = 'Charters';

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
