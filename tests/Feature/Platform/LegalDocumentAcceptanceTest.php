<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use App\Models\LegalDocument;
use App\Models\User;
use App\Support\LegalDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

/**
 * Recording a user's acceptance of the currently-active Privacy Policy /
 * Terms of Use — see App\Http\Controllers\Api\Auth\LegalAcceptanceController.
 */
class LegalDocumentAcceptanceTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    public function test_meta_legal_returns_null_when_nothing_is_published_yet(): void
    {
        $this->getJson('/api/meta/legal')
            ->assertOk()
            ->assertJsonPath('data.privacy_policy', null)
            ->assertJsonPath('data.terms_of_use', null);
    }

    public function test_meta_legal_is_public_and_returns_active_content(): void
    {
        $admin = User::factory()->superAdmin()->create();
        LegalDocuments::publish(LegalDocument::TYPE_PRIVACY_POLICY, 'Privacy Policy', 'Body text.', '2026-09-17', $admin);

        $this->getJson('/api/meta/legal')
            ->assertOk()
            ->assertJsonPath('data.privacy_policy.version', 1)
            ->assertJsonPath('data.privacy_policy.content', 'Body text.')
            ->assertJsonPath('data.terms_of_use', null);
    }

    public function test_a_user_can_accept_the_currently_active_versions(): void
    {
        $admin = User::factory()->superAdmin()->create();
        LegalDocuments::publish(LegalDocument::TYPE_PRIVACY_POLICY, 'Privacy Policy', 'v1', '2026-09-17', $admin);
        LegalDocuments::publish(LegalDocument::TYPE_TERMS_OF_USE, 'Terms of Use', 'v1', '2026-09-17', $admin);

        $company = Company::factory()->create();
        $conductor = User::factory()->forCompany($company)->withRole('conductor')->create();
        Sanctum::actingAs($conductor);

        $this->assertTrue($conductor->needsLegalAcceptance());

        $this->postJson('/api/legal/accept', [
            'privacy_version' => 1, 'terms_version' => 1, 'app_version' => '1.0.0', 'platform' => 'android',
        ])->assertOk();

        $this->assertDatabaseHas('legal_document_acceptances', [
            'user_id' => $conductor->id, 'privacy_policy_version' => 1, 'terms_version' => 1, 'platform' => 'android',
        ]);
        $this->assertFalse($conductor->fresh()->needsLegalAcceptance());
    }

    public function test_accepting_a_stale_version_is_rejected(): void
    {
        $admin = User::factory()->superAdmin()->create();
        LegalDocuments::publish(LegalDocument::TYPE_PRIVACY_POLICY, 'Privacy Policy', 'v1', '2026-09-17', $admin);
        LegalDocuments::publish(LegalDocument::TYPE_PRIVACY_POLICY, 'Privacy Policy', 'v2', '2026-09-18', $admin);
        LegalDocuments::publish(LegalDocument::TYPE_TERMS_OF_USE, 'Terms of Use', 'v1', '2026-09-17', $admin);

        Sanctum::actingAs(User::factory()->forCompany(Company::factory()->create())->withRole('conductor')->create());

        $this->postJson('/api/legal/accept', [
            'privacy_version' => 1, 'terms_version' => 1, 'platform' => 'android',
        ])->assertStatus(422)->assertJsonValidationErrorFor('privacy_version');
    }

    public function test_accepting_requires_authentication(): void
    {
        $this->postJson('/api/legal/accept', [
            'privacy_version' => 1, 'terms_version' => 1, 'platform' => 'android',
        ])->assertUnauthorized();
    }

    public function test_a_user_who_never_accepted_needs_acceptance_once_a_document_is_published(): void
    {
        $company = Company::factory()->create();
        $conductor = User::factory()->forCompany($company)->withRole('conductor')->create();

        $this->assertFalse($conductor->needsLegalAcceptance());

        $admin = User::factory()->superAdmin()->create();
        LegalDocuments::publish(LegalDocument::TYPE_PRIVACY_POLICY, 'Privacy Policy', 'v1', '2026-09-17', $admin);

        $this->assertTrue($conductor->fresh()->needsLegalAcceptance());
    }
}
