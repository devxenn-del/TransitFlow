<?php

namespace Tests\Feature\Platform;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Production runs `php artisan db:seed --force` after every deploy, so the
 * full seeder chain must succeed against the current schema and be safe to
 * run again on an already-seeded database.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_full_seeder_chain_runs_repeatedly(): void
    {
        $this->seed();
        $this->seed();

        $this->assertDatabaseCount('fees', 3);
    }

    /**
     * The deploy-time re-seed must never put the bootstrap Super Admin's
     * default password (or email/status) back once it has been changed.
     */
    public function test_reseeding_leaves_an_existing_super_admin_untouched(): void
    {
        $this->seed();

        $superAdmin = User::query()->where('role', UserRole::SuperAdmin->value)->sole();
        $superAdmin->update([
            'email' => 'owner@perjoda.com',
            'password' => Hash::make('a-real-secret'),
        ]);

        $this->seed();

        $this->assertSame(1, User::query()->where('role', UserRole::SuperAdmin->value)->count());
        $this->assertDatabaseMissing('users', ['email' => 'superadmin@transitflow.test']);
        $this->assertTrue(Hash::check('a-real-secret', $superAdmin->fresh()->password));
    }

    /**
     * Companies, their franchises, routes, route stops and fare matrices are
     * real operator data, entered or imported in the app — the deploy-time
     * seed must never create or overwrite any of it.
     */
    public function test_seeding_creates_no_company_data(): void
    {
        $this->seed();

        $this->assertSame(0, Company::query()->count());
        $this->assertDatabaseCount('franchises', 0);
        $this->assertDatabaseCount('route_stops', 0);
        $this->assertDatabaseCount('routes', 0);
    }
}
