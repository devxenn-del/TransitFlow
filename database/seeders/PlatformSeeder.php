<?php

namespace Database\Seeders;

use App\Actions\SyncUserRolePermissions;
use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The TransitFlow platform Super Admin — a company-less account that
 * operates the platform itself. Depends on RbacSeeder having run.
 *
 * Only bootstraps a fresh install: once any Super Admin exists it does
 * nothing, so re-running `db:seed` on deploy never resets that account's
 * password, email or status back to the defaults.
 */
class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        if (User::query()->where('role', UserRole::SuperAdmin->value)->exists()) {
            return;
        }

        $superAdmin = User::query()->create([
            'email' => 'superadmin@transitflow.test',
            'name' => 'TransitFlow Super Admin',
            'password' => Hash::make('password'),
            'company_id' => null,
            'role' => UserRole::SuperAdmin->value,
            'role_id' => Role::query()->where('key', 'super_admin')->value('id'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        app(SyncUserRolePermissions::class)->handle($superAdmin, reset: true);
    }
}
