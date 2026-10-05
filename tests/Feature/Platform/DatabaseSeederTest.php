<?php

namespace Tests\Feature\Platform;

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

        $this->assertDatabaseHas('users', ['email' => 'manager@perjoda.test']);
        $this->assertDatabaseCount('fees', 3);
    }
}
