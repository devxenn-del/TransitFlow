<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::factory()->create();
        $this->user = User::factory()->companyAdmin($company)->create([
            'password' => Hash::make('temp-password'),
            'must_change_password' => true,
        ]);
    }

    public function test_the_rest_of_the_api_is_locked_until_the_password_is_changed(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/company/terminals')->assertStatus(423);
        // The escape hatches stay open.
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.must_change_password', true);
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/auth/password', [
            'current_password' => 'nope', 'password' => 'a-brand-new-one', 'password_confirmation' => 'a-brand-new-one',
        ])->assertJsonValidationErrorFor('current_password');
    }

    public function test_setting_a_new_password_clears_the_flag_and_unlocks_the_api(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/auth/password', [
            'current_password' => 'temp-password',
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-one',
        ])->assertOk()->assertJsonPath('user.must_change_password', false);

        $this->user->refresh();
        $this->assertFalse($this->user->must_change_password);
        $this->assertNotNull($this->user->password_changed_at);
        $this->assertTrue(Hash::check('a-brand-new-one', $this->user->password));

        $this->getJson('/api/company/terminals')->assertOk();
    }

    public function test_the_new_password_must_differ_from_the_temporary_one(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/auth/password', [
            'current_password' => 'temp-password', 'password' => 'temp-password', 'password_confirmation' => 'temp-password',
        ])->assertJsonValidationErrorFor('password');
    }

    public function test_a_normal_user_without_the_flag_is_not_locked(): void
    {
        $normal = User::factory()->companyAdmin($this->user->company)->create();
        Sanctum::actingAs($normal);

        $this->getJson('/api/company/terminals')->assertOk();
    }
}
