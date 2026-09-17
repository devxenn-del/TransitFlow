<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use App\Models\LegalDocument;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

/**
 * Super Admin "Legal Documents" CMS — publish/version the Privacy Policy
 * and Terms of Use. See App\Http\Controllers\Api\SuperAdmin\LegalDocumentAdminController.
 */
class LegalDocumentAdminTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    public function test_a_super_admin_can_publish_the_first_version_of_a_document(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson('/api/super-admin/legal-documents', [
            'type' => 'privacy_policy', 'title' => 'Privacy Policy', 'content' => 'Body.', 'effective_date' => '2026-09-17',
        ])
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseCount('legal_documents', 1);
    }

    public function test_publishing_again_increments_the_version_and_deactivates_the_previous_one(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson('/api/super-admin/legal-documents', [
            'type' => 'privacy_policy', 'title' => 'Privacy Policy', 'content' => 'v1', 'effective_date' => '2026-09-17',
        ])->assertCreated();

        $this->postJson('/api/super-admin/legal-documents', [
            'type' => 'privacy_policy', 'title' => 'Privacy Policy', 'content' => 'v2', 'effective_date' => '2026-10-01',
        ])
            ->assertCreated()
            ->assertJsonPath('data.version', 2);

        $this->assertSame(1, LegalDocument::query()->where('type', 'privacy_policy')->where('is_active', true)->count());
        $this->assertSame(2, LegalDocument::activeVersion('privacy_policy')->version);
    }

    public function test_active_returns_404_when_nothing_has_been_published_for_that_type(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->getJson('/api/super-admin/legal-documents/terms_of_use/active')->assertNotFound();
    }

    public function test_a_company_admin_cannot_view_or_manage_legal_documents(): void
    {
        Sanctum::actingAs(User::factory()->companyAdmin(Company::factory()->create())->create());

        $this->getJson('/api/super-admin/legal-documents')->assertForbidden();
        $this->postJson('/api/super-admin/legal-documents', [
            'type' => 'privacy_policy', 'title' => 'x', 'content' => 'x', 'effective_date' => '2026-09-17',
        ])->assertForbidden();
    }
}
