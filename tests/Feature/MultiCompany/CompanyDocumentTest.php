<?php

namespace Tests\Feature\MultiCompany;

use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class CompanyDocumentTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->company = Company::factory()->create();
    }

    public function test_company_admin_can_upload_list_and_download_a_document(): void
    {
        $admin = User::factory()->companyAdmin($this->company)->create();
        Sanctum::actingAs($admin);

        $id = $this->post('/api/company/documents', [
            'name' => 'LTFRB Franchise 2026',
            'category' => 'franchise',
            'expires_at' => now()->addYear()->toDateString(),
            'file' => UploadedFile::fake()->create('franchise.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.category_label', 'Franchise / CPC')
            ->assertJsonPath('data.status', 'valid')
            ->assertJsonPath('data.uploaded_by.id', $admin->id)
            ->json('data.id');

        $document = CompanyDocument::query()->findOrFail($id);
        $this->assertSame($this->company->id, $document->company_id);
        Storage::disk('local')->assertExists($document->file_path);

        $this->getJson('/api/company/documents?category=franchise')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/company/documents?category=permit')->assertOk()->assertJsonCount(0, 'data');
        $this->get("/api/company/documents/{$id}/download")->assertOk()->assertDownload('franchise.pdf');
    }

    public function test_replacing_a_file_removes_the_old_one(): void
    {
        Sanctum::actingAs(User::factory()->companyAdmin($this->company)->create());

        $id = $this->post('/api/company/documents', [
            'name' => 'Permit', 'category' => 'permit',
            'file' => UploadedFile::fake()->create('old.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $oldPath = CompanyDocument::query()->findOrFail($id)->file_path;

        $this->post("/api/company/documents/{$id}", [
            'file' => UploadedFile::fake()->create('new.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.original_name', 'new.pdf');

        Storage::disk('local')->assertMissing($oldPath);
    }

    public function test_another_companys_document_is_not_found(): void
    {
        $foreign = CompanyDocument::factory()->create(['company_id' => Company::factory()->create()->id]);
        Sanctum::actingAs(User::factory()->companyAdmin($this->company)->create());

        $this->getJson("/api/company/documents/{$foreign->id}")->assertNotFound();
        $this->get("/api/company/documents/{$foreign->id}/download", ['Accept' => 'application/json'])->assertNotFound();
        $this->deleteJson("/api/company/documents/{$foreign->id}")->assertNotFound();
        $this->getJson('/api/company/documents')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_view_only_role_cannot_upload(): void
    {
        // Office role: documents.view only.
        Sanctum::actingAs(User::factory()->forCompany($this->company)->create());

        $this->getJson('/api/company/documents')->assertOk();
        $this->post('/api/company/documents', [
            'name' => 'X', 'category' => 'other',
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_any_kind_of_company_document_can_be_uploaded_and_marked_important(): void
    {
        Sanctum::actingAs(User::factory()->companyAdmin($this->company)->create());

        $this->post('/api/company/documents', [
            'name' => 'Comprehensive insurance — Bus 12',
            'category' => 'insurance',
            'reference_number' => 'POL-2026-0042',
            'issued_at' => '2026-01-10',
            'expires_at' => '2027-01-10',
            'is_important' => '1',
            'file' => UploadedFile::fake()->create('policy.xlsx', 30),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.is_important', true)
            ->assertJsonPath('data.reference_number', 'POL-2026-0042')
            ->assertJsonPath('data.file_kind', 'spreadsheet');
    }

    public function test_expiry_cannot_precede_issue_date(): void
    {
        Sanctum::actingAs(User::factory()->companyAdmin($this->company)->create());

        $this->post('/api/company/documents', [
            'name' => 'Permit', 'category' => 'permit',
            'issued_at' => '2026-05-01', 'expires_at' => '2026-04-01',
            'file' => UploadedFile::fake()->create('permit.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('expires_at');
    }

    public function test_library_filters_by_importance_status_and_file_kind_with_important_first(): void
    {
        Sanctum::actingAs(User::factory()->companyAdmin($this->company)->create());
        $plain = CompanyDocument::factory()->create(['company_id' => $this->company->id, 'original_name' => 'a.pdf']);
        $starred = CompanyDocument::factory()->create(['company_id' => $this->company->id, 'is_important' => true, 'original_name' => 'b.png']);
        $expired = CompanyDocument::factory()->expired()->create(['company_id' => $this->company->id, 'original_name' => 'c.docx']);
        $expiring = CompanyDocument::factory()->create(['company_id' => $this->company->id, 'expires_at' => now()->addDays(10)->toDateString(), 'original_name' => 'd.pdf']);

        $this->assertSame($starred->id, $this->getJson('/api/company/documents')->json('data.0.id'));
        $this->assertSame([$starred->id], collect($this->getJson('/api/company/documents?important=1')->json('data'))->pluck('id')->all());
        $this->assertSame([$expired->id], collect($this->getJson('/api/company/documents?status=expired')->json('data'))->pluck('id')->all());
        $this->assertSame([$expiring->id], collect($this->getJson('/api/company/documents?status=expiring')->json('data'))->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$plain->id, $expiring->id], collect($this->getJson('/api/company/documents?file_kind=pdf')->json('data'))->pluck('id')->all());

        $this->getJson('/api/company/documents/summary')
            ->assertOk()
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.important', 1)
            ->assertJsonPath('data.expired', 1)
            ->assertJsonPath('data.expiring', 1);
    }

    public function test_starring_needs_manage_permission(): void
    {
        $document = CompanyDocument::factory()->create(['company_id' => $this->company->id]);

        Sanctum::actingAs(User::factory()->forCompany($this->company)->create());
        $this->patchJson("/api/company/documents/{$document->id}/important", ['is_important' => true])->assertForbidden();

        Sanctum::actingAs(User::factory()->companyAdmin($this->company)->create());
        $this->patchJson("/api/company/documents/{$document->id}/important", ['is_important' => true])
            ->assertOk()
            ->assertJsonPath('data.is_important', true);
    }

    public function test_expired_documents_are_flagged(): void
    {
        $document = CompanyDocument::factory()->expired()->create(['company_id' => $this->company->id]);

        $this->assertSame('expired', $document->status());
    }
}
