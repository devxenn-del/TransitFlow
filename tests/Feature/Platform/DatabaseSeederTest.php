<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
