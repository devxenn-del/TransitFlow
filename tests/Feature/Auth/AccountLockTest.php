<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\User;
use App\Support\AccountLock;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class AccountLockTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    public function test_an_admin_locked_account_cannot_log_in(): void
    {
        $company = Company::factory()->create();
        $target = User::factory()->forCompany($company)->create(['email' => 'locked@acme.test']);
        AccountLock::lockAdmin($target, User::factory()->companyAdmin($company)->create());

        $this->postJson('/api/auth/login', ['email' => 'locked@acme.test', 'password' => 'password'])
            ->assertStatus(423)
            ->assertJsonPath('lock_type', 'admin');
    }

    public function test_a_locked_account_is_rejected_on_every_subsequent_request_except_logout(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->forCompany($company)->create();
        Sanctum::actingAs($user);

        AccountLock::lockAdmin($user, User::factory()->companyAdmin($company)->create());

        $this->getJson('/api/auth/me')->assertStatus(423);
        $this->postJson('/api/auth/logout')->assertOk();
    }

    public function test_a_company_admin_can_lock_and_unlock_a_user(): void
    {
        $company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->companyAdmin($company)->create());
        $target = User::factory()->forCompany($company)->create();

        $this->postJson("/api/company/users/{$target->id}/lock")
            ->assertOk()
            ->assertJsonPath('data.is_locked', true)
            ->assertJsonPath('data.lock_type', 'admin');

        $this->assertTrue(AccountLock::isLocked($target->fresh()));

        $this->postJson("/api/company/users/{$target->id}/unlock")
            ->assertOk()
            ->assertJsonPath('data.is_locked', false);

        $this->assertFalse(AccountLock::isLocked($target->fresh()));
    }

    public function test_a_shift_end_lock_bites_immediately_on_a_fresh_login_but_grants_grace_to_an_established_session(): void
    {
        $company = Company::factory()->create();
        $driver = \App\Models\Driver::factory()->for($company)->create();
        $user = User::factory()->forCompany($company)->withRole('conductor')
            ->create(['email' => 'c@acme.test', 'driver_id' => $driver->id]);
        AccountLock::lockShiftEnd($user);

        // Fresh sign-in: no grace, locked immediately.
        $this->postJson('/api/auth/login', ['email' => 'c@acme.test', 'password' => 'password', 'driver_code' => $driver->driver_code])
            ->assertStatus(423)
            ->assertJsonPath('lock_type', 'shift_end');

        // Established session: grace window still lets the request through.
        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/auth/me')->assertOk();
    }

    public function test_a_shift_end_lock_auto_expires_at_the_next_359_am(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->forCompany($company)->create(['email' => 'c2@acme.test']);

        $this->travelTo(Carbon::parse('2026-09-09 10:00:00'));
        AccountLock::lockShiftEnd($user);
        AccountLock::endShiftEndGraceNow($user->fresh());

        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/auth/me')->assertStatus(423);

        $this->travelTo(Carbon::parse('2026-09-10 04:00:00'));
        $this->getJson('/api/auth/me')->assertOk();
    }
}
