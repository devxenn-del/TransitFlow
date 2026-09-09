<?php

namespace Tests\Concerns;

use Database\Seeders\RbacSeeder;

/**
 * Seeds the roles/permissions catalogue for a feature test. Laravel calls
 * `setUpSeedsRbac()` automatically after the base `setUp()` (and after
 * RefreshDatabase has migrated), so the UserFactory role states have roles
 * to attach.
 */
trait SeedsRbac
{
    public function setUpSeedsRbac(): void
    {
        $this->seed(RbacSeeder::class);
    }
}
