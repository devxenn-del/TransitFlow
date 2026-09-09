<?php

namespace Tests\Feature\MultiCompany;

use App\Models\Company;
use App\Models\MobileAppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class MobileAppTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create(['code' => 'PERJODA', 'name' => 'Perjoda Transit']);
        $this->admin = User::factory()->companyAdmin($this->company)->create();
    }

    public function test_an_admin_publishes_the_app_version_and_it_stamps_published_at(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/company/mobile-app', [
            'latest_version' => '1.4.2',
            'latest_version_code' => 42,
            'minimum_version' => '1.2.0',
            'force_update' => true,
            'download_url' => 'https://cdn.example.test/perjoda.apk',
            'release_notes' => 'Offline ticketing fixes.',
        ])
            ->assertOk()
            ->assertJsonPath('data.latest_version', '1.4.2')
            ->assertJsonPath('data.force_update', true);

        $this->assertNotNull(MobileAppSetting::query()->first()->published_at);
    }

    public function test_publish_validation_rejects_a_bad_version_string_and_url(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/company/mobile-app', [
            'latest_version' => 'v1.4-beta', 'download_url' => 'not-a-url',
        ])->assertStatus(422)->assertJsonValidationErrors(['latest_version', 'download_url']);
    }

    public function test_the_public_server_config_is_reachable_without_auth_by_company_code(): void
    {
        MobileAppSetting::factory()->for($this->company)->create([
            'latest_version' => '2.0.0', 'latest_version_code' => 20, 'minimum_version' => '1.5.0',
            'download_url' => 'https://cdn.example.test/app.apk',
            'api_base_url' => null,
        ]);

        // APP_URL is localhost in tests → api_base_url is null so a mobile
        // client keeps whatever host it actually reached us on.
        $this->getJson('/api/meta/server-config?company=PERJODA')
            ->assertOk()
            ->assertJsonPath('data.company.code', 'PERJODA')
            ->assertJsonPath('data.app.latest_version', '2.0.0')
            ->assertJsonPath('data.app.download_url', 'https://cdn.example.test/app.apk')
            ->assertJsonPath('data.api_base_url', null);
    }

    public function test_server_config_advertises_an_explicit_or_routable_api_base_url(): void
    {
        MobileAppSetting::factory()->for($this->company)->create(['api_base_url' => 'https://ops.perjoda.test/api']);
        $this->getJson('/api/meta/server-config?company=PERJODA')
            ->assertOk()->assertJsonPath('data.api_base_url', 'https://ops.perjoda.test/api');

        MobileAppSetting::query()->where('company_id', $this->company->id)->update(['api_base_url' => null]);
        config(['app.url' => 'https://api.transitflow.test']);
        $this->getJson('/api/meta/server-config?company=PERJODA')
            ->assertOk()->assertJsonPath('data.api_base_url', 'https://api.transitflow.test/api');
    }

    public function test_server_config_404s_for_an_unknown_or_inactive_company(): void
    {
        $this->getJson('/api/meta/server-config?company=NOPE')->assertNotFound();
        $this->getJson('/api/meta/server-config')->assertStatus(422);

        Company::factory()->inactive()->create(['code' => 'DEAD']);
        $this->getJson('/api/meta/server-config?company=DEAD')->assertNotFound();
    }

    public function test_check_update_reports_available_and_forced_correctly(): void
    {
        MobileAppSetting::factory()->for($this->company)->create([
            'latest_version' => '1.5.0', 'latest_version_code' => 15,
            'minimum_version' => '1.3.0', 'force_update' => false,
        ]);

        // Up to date.
        $this->getJson('/api/meta/check-update?company=PERJODA&version=1.5.0&version_code=15')
            ->assertOk()
            ->assertJsonPath('data.update_available', false)
            ->assertJsonPath('data.force_update', false);

        // Newer available, not forced (still above minimum).
        $this->getJson('/api/meta/check-update?company=PERJODA&version=1.4.0&version_code=14')
            ->assertOk()
            ->assertJsonPath('data.update_available', true)
            ->assertJsonPath('data.force_update', false);

        // Below the minimum → forced.
        $this->getJson('/api/meta/check-update?company=PERJODA&version=1.2.0&version_code=12')
            ->assertOk()
            ->assertJsonPath('data.update_available', true)
            ->assertJsonPath('data.force_update', true);
    }

    public function test_check_update_forces_when_the_force_update_flag_is_set(): void
    {
        MobileAppSetting::factory()->for($this->company)->create([
            'latest_version' => '1.5.0', 'latest_version_code' => 15,
            'minimum_version' => '1.0.0', 'force_update' => true,
        ]);

        $this->getJson('/api/meta/check-update?company=PERJODA&version=1.4.0&version_code=14')
            ->assertOk()
            ->assertJsonPath('data.force_update', true);
    }

    public function test_an_apk_upload_is_stored_and_served_when_no_download_url_is_set(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/company/mobile-app/apk', [
            'apk' => UploadedFile::fake()->create('perjoda.apk', 2048, 'application/vnd.android.package-archive'),
        ])->assertOk();

        $path = $response->json('data.apk_path');
        $this->assertStringContainsString("company-{$this->company->id}/app", $path);
        Storage::disk('public')->assertExists($path);

        // With no explicit download_url, the public config serves the uploaded APK.
        $url = $this->getJson('/api/meta/server-config?company=PERJODA')->json('data.app.download_url');
        $this->assertNotNull($url);

        $this->deleteJson('/api/company/mobile-app/apk')->assertOk()->assertJsonPath('data.apk_path', null);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_non_apk_upload_is_rejected(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/company/mobile-app/apk', [
            'apk' => UploadedFile::fake()->create('app.zip', 100),
        ])->assertStatus(422)->assertJsonValidationErrors('apk');
    }

    public function test_settings_are_company_scoped_and_permission_gated(): void
    {
        $other = Company::factory()->create(['code' => 'SOUTH']);
        MobileAppSetting::factory()->for($other)->create(['latest_version' => '9.9.9']);
        $otherAdmin = User::factory()->companyAdmin($other)->create();

        Sanctum::actingAs($this->admin);
        $this->putJson('/api/company/mobile-app', ['latest_version' => '1.0.1'])->assertOk();

        Sanctum::actingAs($otherAdmin);
        $this->getJson('/api/company/mobile-app')->assertOk()->assertJsonPath('data.latest_version', '9.9.9');

        // A plain company user cannot read or write.
        $office = User::factory()->forCompany($this->company)->withRole('office')->create();
        Sanctum::actingAs($office);
        $this->getJson('/api/company/mobile-app')->assertForbidden();
        $this->putJson('/api/company/mobile-app', ['latest_version' => '0.0.1'])->assertForbidden();
    }

    public function test_a_chairman_may_view_but_not_publish(): void
    {
        MobileAppSetting::factory()->for($this->company)->create();
        $chairman = User::factory()->forCompany($this->company)->withRole('chairman')->create();

        Sanctum::actingAs($chairman);
        $this->getJson('/api/company/mobile-app')->assertOk();
        $this->putJson('/api/company/mobile-app', ['latest_version' => '1.0.1'])->assertForbidden();
    }
}
