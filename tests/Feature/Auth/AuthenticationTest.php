<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    public function test_a_user_can_log_in_and_receives_a_token_and_profile(): void
    {
        $company = Company::factory()->create();
        User::factory()->companyAdmin($company)->create([
            'email' => 'admin@acme.test',
            'password' => Hash::make('secret-password'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@acme.test',
            'password' => 'secret-password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'role', 'permissions', 'company']])
            ->assertJsonPath('user.email', 'admin@acme.test')
            ->assertJsonPath('user.is_company_admin', true);

        $this->assertContains('buses.view', $response->json('user.permissions'));
    }

    public function test_login_fails_with_a_wrong_password(): void
    {
        User::factory()->create(['email' => 'x@acme.test', 'password' => Hash::make('right')]);

        $this->postJson('/api/auth/login', ['email' => 'x@acme.test', 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }

    public function test_an_inactive_account_cannot_log_in(): void
    {
        User::factory()->inactive()->create([
            'email' => 'off@acme.test', 'password' => Hash::make('secret-password'),
        ]);

        $this->postJson('/api/auth/login', ['email' => 'off@acme.test', 'password' => 'secret-password'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }

    public function test_a_user_of_a_suspended_company_cannot_log_in(): void
    {
        $company = Company::factory()->suspended()->create();
        User::factory()->companyAdmin($company)->create([
            'email' => 'sus@acme.test', 'password' => Hash::make('secret-password'),
        ]);

        $this->postJson('/api/auth/login', ['email' => 'sus@acme.test', 'password' => 'secret-password'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'rl@acme.test', 'password' => Hash::make('right')]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'rl@acme.test', 'password' => 'wrong'])->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['email' => 'rl@acme.test', 'password' => 'right'])->assertStatus(429);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_me_returns_the_authenticated_user_with_permissions(): void
    {
        Sanctum::actingAs(User::factory()->companyAdmin()->create());

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.is_company_admin', true)
            ->assertJsonStructure(['data' => ['permissions']]);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->companyAdmin()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Fresh guard (each real request re-authenticates from scratch).
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }
}
