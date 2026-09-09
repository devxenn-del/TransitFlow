<?php

namespace Tests\Feature\MultiCompany;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class CompanySettingsTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create(['name' => 'Perjoda Transit']);
        $this->admin = User::factory()->companyAdmin($this->company)->create();
    }

    public function test_show_creates_a_settings_row_seeded_from_the_company_name_when_none_exists(): void
    {
        $this->assertDatabaseMissing('company_settings', ['company_id' => $this->company->id]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/company/settings')
            ->assertOk()
            ->assertJsonPath('data.receipt_org_name', 'Perjoda Transit')
            ->assertJsonPath('data.color_accent', '#0d6efd');

        $this->assertDatabaseHas('company_settings', ['company_id' => $this->company->id]);
    }

    public function test_a_company_admin_updates_branding_receipt_and_org_identity_fields(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/company/settings', [
            'color_accent' => '#F5A623',
            'color_accent_dark' => '#c8860f',
            'receipt_width_mm' => 58,
            'receipt_org_name' => 'PERJODA TRANSIT CORP.',
            'ticket_footer' => 'Salamat sa pagsakay!',
            'registration_number' => 'SEC-2021-0099',
            'otc_accreditation_number' => 'OTC-AC-1234',
            'org_email' => 'ops@perjoda.test',
            'org_contact_number' => '0917-000-1111',
        ])
            ->assertOk()
            ->assertJsonPath('data.receipt_org_name', 'PERJODA TRANSIT CORP.')
            ->assertJsonPath('data.registration_number', 'SEC-2021-0099')
            ->assertJsonPath('data.receipt_width_mm', '58.0');

        $this->assertDatabaseHas('company_settings', [
            'company_id' => $this->company->id,
            'ticket_footer' => 'Salamat sa pagsakay!',
            'org_email' => 'ops@perjoda.test',
        ]);
    }

    public function test_invalid_accent_colour_and_out_of_range_receipt_width_are_rejected(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/company/settings', ['color_accent' => 'red', 'receipt_width_mm' => 500])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['color_accent', 'receipt_width_mm']);
    }

    public function test_report_pdf_page_setup_is_stored_and_validated(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/company/settings', [
            'pdf_paper_size' => 'letter', 'pdf_orientation' => 'portrait', 'pdf_margin_mm' => 15,
        ])
            ->assertOk()
            ->assertJsonPath('data.pdf_paper_size', 'letter')
            ->assertJsonPath('data.pdf_orientation', 'portrait')
            ->assertJsonPath('data.pdf_margin_mm', 15);

        $this->putJson('/api/company/settings', ['pdf_paper_size' => 'a3', 'pdf_margin_mm' => 99])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pdf_paper_size', 'pdf_margin_mm']);
    }

    public function test_a_report_pdf_still_renders_after_changing_the_page_setup(): void
    {
        CompanySetting::factory()->for($this->company)->create([
            'pdf_paper_size' => 'legal', 'pdf_orientation' => 'portrait', 'pdf_margin_mm' => 20,
        ]);

        Sanctum::actingAs($this->admin);

        $this->get('/api/company/reports/income?format=pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_configuration_snapshot_reports_non_secret_runtime_values(): void
    {
        CompanySetting::factory()->for($this->company)->create();

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/company/configuration')
            ->assertOk()
            ->assertJsonPath('data.platform.timezone', config('app.timezone'))
            ->assertJsonPath('data.company.name', 'Perjoda Transit')
            ->assertJsonStructure(['data' => [
                'platform' => ['app_name', 'environment', 'laravel_version', 'php_version', 'currency'],
                'company' => ['code', 'status', 'can_create_accounts'],
                'features' => ['void_feature_enabled', 'pdf_paper_size'],
            ]])
            ->assertJsonMissingPath('data.platform.app_key')
            ->assertJsonMissingPath('data.platform.db_password');
    }

    public function test_configuration_needs_the_settings_view_permission_and_is_company_scoped(): void
    {
        $office = User::factory()->forCompany($this->company)->withRole('office')->create();
        Sanctum::actingAs($office);
        $this->getJson('/api/company/configuration')->assertForbidden();

        $other = Company::factory()->create(['name' => 'Other Co']);
        $otherAdmin = User::factory()->companyAdmin($other)->create();
        Sanctum::actingAs($otherAdmin);
        $this->getJson('/api/company/configuration')->assertOk()->assertJsonPath('data.company.name', 'Other Co');
    }

    public function test_nullable_org_fields_are_coalesced_to_empty_string_not_null(): void
    {
        CompanySetting::factory()->for($this->company)->create(['registration_number' => 'OLD']);

        Sanctum::actingAs($this->admin);

        $this->putJson('/api/company/settings', ['registration_number' => null])
            ->assertOk()
            ->assertJsonPath('data.registration_number', '');

        $this->assertDatabaseHas('company_settings', [
            'company_id' => $this->company->id,
            'registration_number' => '',
        ]);
    }

    public function test_logo_upload_stores_the_file_and_records_a_public_url(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/company/settings/logo', [
            'image' => UploadedFile::fake()->image('logo.png', 200, 200),
        ])->assertOk();

        $path = $response->json('data.logo_path');
        $this->assertNotNull($path);
        $this->assertStringContainsString("company-{$this->company->id}/branding", $path);
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($response->json('data.logo_url'));
    }

    public function test_uploading_a_new_logo_removes_the_previous_file(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->admin);

        $first = $this->postJson('/api/company/settings/logo', ['image' => UploadedFile::fake()->image('a.png')])
            ->json('data.logo_path');
        $second = $this->postJson('/api/company/settings/logo', ['image' => UploadedFile::fake()->image('b.png')])
            ->json('data.logo_path');

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_deleting_the_qr_payment_image_clears_the_path_and_file(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->admin);

        $path = $this->postJson('/api/company/settings/qr-payment', ['image' => UploadedFile::fake()->image('qr.png')])
            ->json('data.qr_payment_path');

        $this->deleteJson('/api/company/settings/qr-payment')
            ->assertOk()
            ->assertJsonPath('data.qr_payment_path', null);

        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_non_png_jpg_webp_upload_is_rejected(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/company/settings/logo', [
            'image' => UploadedFile::fake()->create('logo.pdf', 40, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_an_unknown_image_type_segment_404s(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/company/settings/banner', ['image' => UploadedFile::fake()->image('x.png')])
            ->assertNotFound();
    }

    public function test_settings_are_isolated_between_companies(): void
    {
        $other = Company::factory()->create();
        CompanySetting::factory()->for($other)->create(['receipt_org_name' => 'SECRET CO']);
        $otherAdmin = User::factory()->companyAdmin($other)->create();

        Sanctum::actingAs($this->admin);
        $this->putJson('/api/company/settings', ['receipt_org_name' => 'MINE'])->assertOk();

        Sanctum::actingAs($otherAdmin);
        $this->getJson('/api/company/settings')
            ->assertOk()
            ->assertJsonPath('data.receipt_org_name', 'SECRET CO');
    }

    public function test_a_plain_company_user_cannot_read_or_write_settings(): void
    {
        $user = User::factory()->forCompany($this->company)->withRole('office')->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/company/settings')->assertForbidden();
        $this->putJson('/api/company/settings', ['receipt_org_name' => 'nope'])->assertForbidden();
        $this->postJson('/api/company/settings/logo', ['image' => UploadedFile::fake()->image('x.png')])->assertForbidden();
    }

    public function test_a_chairman_may_view_settings_but_not_change_them(): void
    {
        CompanySetting::factory()->for($this->company)->create();
        $chairman = User::factory()->forCompany($this->company)->withRole('chairman')->create();

        Sanctum::actingAs($chairman);

        $this->getJson('/api/company/settings')->assertOk();
        $this->putJson('/api/company/settings', ['receipt_org_name' => 'nope'])->assertForbidden();
    }
}
