<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use App\Models\MobileAppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

/**
 * One mobile app, platform-wide — every company's conductors run the same
 * build. Only the Super Admin publishes it; a company code merely proves
 * the caller belongs to a real company before it's handed the download.
 */
class MobileAppTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    public function test_a_super_admin_publishes_the_app_version_and_it_stamps_published_at(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->putJson('/api/super-admin/mobile-app', [
            'latest_version' => '1.4.2',
            'latest_version_code' => 42,
            'minimum_version' => '1.2.0',
            'force_update' => true,
            'download_url' => 'https://cdn.example.test/app.apk',
            'release_notes' => 'Offline ticketing fixes.',
        ])
            ->assertOk()
            ->assertJsonPath('data.latest_version', '1.4.2')
            ->assertJsonPath('data.force_update', true);

        $this->assertNotNull(MobileAppSetting::current()->published_at);
    }

    public function test_publish_validation_rejects_a_bad_version_string_and_url(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->putJson('/api/super-admin/mobile-app', [
            'latest_version' => 'v1.4-beta', 'download_url' => 'not-a-url',
        ])->assertStatus(422)->assertJsonValidationErrors(['latest_version', 'download_url']);
    }

    public function test_the_public_server_config_is_reachable_without_auth_by_company_code(): void
    {
        Company::factory()->create(['code' => 'PERJODA']);
        MobileAppSetting::factory()->create([
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

    public function test_the_app_is_identical_for_every_company_that_asks(): void
    {
        Company::factory()->create(['code' => 'PERJODA']);
        Company::factory()->create(['code' => 'SOUTHLINE']);
        MobileAppSetting::factory()->create([
            'latest_version' => '3.1.0', 'latest_version_code' => 31,
            'download_url' => 'https://cdn.example.test/app.apk',
        ]);

        $a = $this->getJson('/api/meta/server-config?company=PERJODA')->json('data.app');
        $b = $this->getJson('/api/meta/server-config?company=SOUTHLINE')->json('data.app');

        $this->assertSame($a, $b);
        $this->assertSame(1, MobileAppSetting::query()->count());
    }

    public function test_server_config_advertises_an_explicit_or_routable_api_base_url(): void
    {
        Company::factory()->create(['code' => 'PERJODA']);
        MobileAppSetting::factory()->create(['api_base_url' => 'https://ops.transitflow.test/api']);
        $this->getJson('/api/meta/server-config?company=PERJODA')
            ->assertOk()->assertJsonPath('data.api_base_url', 'https://ops.transitflow.test/api');

        MobileAppSetting::current()->update(['api_base_url' => null]);
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
        Company::factory()->create(['code' => 'PERJODA']);
        MobileAppSetting::factory()->create([
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
        Company::factory()->create(['code' => 'PERJODA']);
        MobileAppSetting::factory()->create([
            'latest_version' => '1.5.0', 'latest_version_code' => 15,
            'minimum_version' => '1.0.0', 'force_update' => true,
        ]);

        $this->getJson('/api/meta/check-update?company=PERJODA&version=1.4.0&version_code=14')
            ->assertOk()
            ->assertJsonPath('data.force_update', true);
    }

    public function test_a_super_admin_apk_upload_is_served_under_its_original_filename_from_a_stable_url(): void
    {
        Storage::fake('public');
        Company::factory()->create(['code' => 'PERJODA']);
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $response = $this->postJson('/api/super-admin/mobile-app/apk', [
            'apk' => UploadedFile::fake()->create('Conductor App v1.4.2.apk', 2048, 'application/vnd.android.package-archive'),
        ])->assertOk();

        $path = $response->json('data.apk_path');
        $this->assertSame('Conductor App v1.4.2.apk', $response->json('data.apk_original_name'));
        Storage::disk('public')->assertExists($path);

        // With no explicit download_url, the public config serves the stable download route.
        $url = $this->getJson('/api/meta/server-config?company=PERJODA')->json('data.app.download_url');
        $this->assertSame(route('meta.mobile-app.download'), $url);

        // The download itself carries the real filename via Content-Disposition,
        // not the fixed name the file is actually stored under.
        $this->get($url)
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="Conductor App v1.4.2.apk"');

        // Re-uploading a second build overwrites the same stored path — the
        // download URL a conductor already has never goes stale.
        $second = $this->postJson('/api/super-admin/mobile-app/apk', [
            'apk' => UploadedFile::fake()->create('Conductor App v1.5.0.apk', 2048, 'application/vnd.android.package-archive'),
        ])->assertOk();
        $this->assertSame($path, $second->json('data.apk_path'));
        $this->assertSame(route('meta.mobile-app.download'), $second->json('data.apk_url'));

        $this->deleteJson('/api/super-admin/mobile-app/apk')
            ->assertOk()->assertJsonPath('data.apk_path', null)->assertJsonPath('data.apk_original_name', null);
        Storage::disk('public')->assertMissing($path);
        $this->get(route('meta.mobile-app.download'))->assertNotFound();
    }

    public function test_a_non_apk_upload_is_rejected(): void
    {
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson('/api/super-admin/mobile-app/apk', [
            'apk' => UploadedFile::fake()->create('app.zip', 100),
        ])->assertStatus(422)->assertJsonValidationErrors('apk');
    }

    public function test_a_company_admin_cannot_publish_or_upload_the_apk(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create(['code' => 'PERJODA']);
        $admin = User::factory()->companyAdmin($company)->create();
        Sanctum::actingAs($admin);

        $this->putJson('/api/super-admin/mobile-app', ['latest_version' => '9.9.9'])->assertForbidden();

        $this->postJson('/api/super-admin/mobile-app/apk', [
            'apk' => UploadedFile::fake()->create('app.apk', 2048, 'application/vnd.android.package-archive'),
        ])->assertForbidden();

        $this->deleteJson('/api/super-admin/mobile-app/apk')->assertForbidden();

        // The old company-scoped write routes no longer exist — `mobile-app` is
        // GET-only now (405), and `mobile-app/apk` isn't registered at all (404).
        $this->putJson('/api/company/mobile-app', ['latest_version' => '9.9.9'])->assertStatus(405);
        $this->postJson('/api/company/mobile-app/apk', [
            'apk' => UploadedFile::fake()->create('app.apk', 2048, 'application/vnd.android.package-archive'),
        ])->assertNotFound();
    }

    public function test_a_company_admin_and_chairman_see_the_same_published_settings_read_only(): void
    {
        MobileAppSetting::factory()->create(['latest_version' => '4.2.0']);

        $company = Company::factory()->create(['code' => 'PERJODA']);
        $admin = User::factory()->companyAdmin($company)->create();
        $chairman = User::factory()->forCompany($company)->withRole('chairman')->create();

        Sanctum::actingAs($admin);
        $this->getJson('/api/company/mobile-app')->assertOk()->assertJsonPath('data.latest_version', '4.2.0');

        Sanctum::actingAs($chairman);
        $this->getJson('/api/company/mobile-app')->assertOk()->assertJsonPath('data.latest_version', '4.2.0');

        // A plain company user without mobileapp.view cannot even read it.
        $office = User::factory()->forCompany($company)->withRole('office')->create();
        Sanctum::actingAs($office);
        $this->getJson('/api/company/mobile-app')->assertForbidden();
    }
}
