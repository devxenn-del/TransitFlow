<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\LegalDocument;
use App\Models\User;
use App\Support\LegalDocuments;
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

    /**
     * A new user meets the legal consent gate before the change-password
     * screen, so accepting must work while the temporary password is set.
     */
    public function test_the_legal_documents_can_be_accepted_before_the_password_is_changed(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        LegalDocuments::publish(LegalDocument::TYPE_PRIVACY_POLICY, 'Privacy Policy', 'v1', '2026-09-17', $superAdmin);
        LegalDocuments::publish(LegalDocument::TYPE_TERMS_OF_USE, 'Terms of Use', 'v1', '2026-09-17', $superAdmin);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/legal/accept', ['privacy_version' => 1, 'terms_version' => 1, 'platform' => 'web'])
            ->assertOk();

        $this->assertFalse($this->user->fresh()->needsLegalAcceptance());
        $this->getJson('/api/auth/me')
            ->assertJsonPath('data.needs_legal_acceptance', false)
            ->assertJsonPath('data.must_change_password', true);
        $this->getJson('/api/company/terminals')->assertStatus(423);
    }

    /**
     * Outside the forced change, the current password is still required.
     */
    public function test_a_normal_password_change_still_requires_the_current_password(): void
    {
        $normal = User::factory()->companyAdmin($this->user->company)->create(['password' => Hash::make('old-password')]);
        Sanctum::actingAs($normal);

        $this->postJson('/api/auth/password', [
            'password' => 'a-brand-new-one', 'password_confirmation' => 'a-brand-new-one',
        ])->assertJsonValidationErrorFor('current_password');

        $this->postJson('/api/auth/password', [
            'current_password' => 'nope', 'password' => 'a-brand-new-one', 'password_confirmation' => 'a-brand-new-one',
        ])->assertJsonValidationErrorFor('current_password');
    }

    public function test_setting_a_new_password_without_retyping_the_temporary_one_clears_the_flag(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/auth/password', [
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
            'password' => 'temp-password', 'password_confirmation' => 'temp-password',
        ])->assertJsonValidationErrorFor('password');

        $this->assertTrue($this->user->fresh()->must_change_password);
    }

    public function test_a_normal_user_without_the_flag_is_not_locked(): void
    {
        $normal = User::factory()->companyAdmin($this->user->company)->create();
        Sanctum::actingAs($normal);

        $this->getJson('/api/company/terminals')->assertOk();
    }
}
