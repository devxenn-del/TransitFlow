<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use App\Models\MobileAppSetting;
use App\Models\SystemSetting;
use App\Models\SystemSettingHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class SystemConfigurationTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private function fakeHealthy(string $url): void
    {
        Http::fake([
            rtrim($url, '/').'/health' => Http::response(['status' => 'ok', 'app' => 'TransitFlow'], 200),
        ]);
    }

    public function test_a_super_admin_sees_the_unset_configuration_initially(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->getJson('/api/super-admin/system-configuration')
            ->assertOk()
            ->assertJsonPath('data.api_base_url', null)
            ->assertJsonPath('data.status', 'unset')
            ->assertJsonPath('data.configuration_version', 0);
    }

    public function test_a_super_admin_updates_the_server_url_after_a_successful_test(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->fakeHealthy('https://api.transitflow.example/api');

        $this->putJson('/api/super-admin/system-configuration', ['api_base_url' => 'https://api.transitflow.example/api'])
            ->assertOk()
            ->assertJsonPath('data.api_base_url', 'https://api.transitflow.example/api')
            ->assertJsonPath('data.status', 'connected')
            ->assertJsonPath('data.configuration_version', 1);

        $this->assertSame('https://api.transitflow.example/api', SystemSetting::get('api_base_url'));
        $this->assertDatabaseHas('system_setting_history', [
            'setting_key' => 'api_base_url', 'new_value' => 'https://api.transitflow.example/api', 'status' => 'validated',
        ]);
    }

    public function test_updating_to_an_unreachable_server_is_rejected_and_keeps_the_previous_value(): void
    {
        $admin = User::factory()->superAdmin()->create();
        Sanctum::actingAs($admin);
        $this->fakeHealthy('https://good.example.test/api');
        $this->putJson('/api/super-admin/system-configuration', ['api_base_url' => 'https://good.example.test/api'])->assertOk();

        Http::fake(['https://bad.example.test/api/health' => Http::failedConnection('cURL error 7: Failed to connect')]);

        $this->putJson('/api/super-admin/system-configuration', ['api_base_url' => 'https://bad.example.test/api'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('api_base_url');

        $this->assertSame('https://good.example.test/api', SystemSetting::get('api_base_url'));
        $this->assertDatabaseHas('system_setting_history', ['new_value' => 'https://bad.example.test/api', 'status' => 'failed']);
    }

    public function test_http_urls_are_rejected_in_a_non_local_environment(): void
    {
        $this->app['env'] = 'production';
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->putJson('/api/super-admin/system-configuration', ['api_base_url' => 'http://api.transitflow.example/api'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('api_base_url');
    }

    public function test_loopback_hosts_are_always_rejected(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        foreach (['http://localhost/api', 'http://127.0.0.1:8000/api', 'http://0.0.0.0/api'] as $bad) {
            $this->putJson('/api/super-admin/system-configuration', ['api_base_url' => $bad])
                ->assertStatus(422)
                ->assertJsonValidationErrorFor('api_base_url');
        }
    }

    public function test_test_connection_endpoint_does_not_persist_anything(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->fakeHealthy('https://probe.example.test/api');

        $this->postJson('/api/super-admin/system-configuration/test', ['url' => 'https://probe.example.test/api'])
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        $this->assertNull(SystemSetting::get('api_base_url'));
        $this->assertDatabaseCount('system_setting_history', 0);
    }

    public function test_rollback_restores_the_previous_value(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->fakeHealthy('https://one.example.test/api');
        $this->putJson('/api/super-admin/system-configuration', ['api_base_url' => 'https://one.example.test/api'])->assertOk();

        $this->fakeHealthy('https://two.example.test/api');
        $this->putJson('/api/super-admin/system-configuration', ['api_base_url' => 'https://two.example.test/api'])->assertOk();

        $entry = SystemSettingHistory::query()->where('new_value', 'https://two.example.test/api')->firstOrFail();

        // Re-fake both hosts — rollback re-verifies the target before applying.
        Http::fake([
            'https://one.example.test/api/health' => Http::response(['status' => 'ok', 'app' => 'TransitFlow'], 200),
            'https://two.example.test/api/health' => Http::response(['status' => 'ok', 'app' => 'TransitFlow'], 200),
        ]);

        $this->postJson("/api/super-admin/system-configuration/history/{$entry->id}/rollback")
            ->assertOk()
            ->assertJsonPath('data.api_base_url', 'https://one.example.test/api');

        $this->assertSame('https://one.example.test/api', SystemSetting::get('api_base_url'));
        $this->assertDatabaseHas('system_setting_history', ['status' => 'rolled_back', 'new_value' => 'https://one.example.test/api']);
    }

    public function test_rollback_is_blocked_when_the_previous_server_no_longer_responds(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->fakeHealthy('https://one.example.test/api');
        $this->putJson('/api/super-admin/system-configuration', ['api_base_url' => 'https://one.example.test/api'])->assertOk();

        $this->fakeHealthy('https://two.example.test/api');
        $this->putJson('/api/super-admin/system-configuration', ['api_base_url' => 'https://two.example.test/api'])->assertOk();

        $entry = SystemSettingHistory::query()->where('new_value', 'https://two.example.test/api')->firstOrFail();

        Http::fake(['https://one.example.test/api/health' => Http::failedConnection('cURL error 7: Failed to connect')]);

        $this->postJson("/api/super-admin/system-configuration/history/{$entry->id}/rollback")
            ->assertStatus(422);

        $this->assertSame('https://two.example.test/api', SystemSetting::get('api_base_url'));
    }

    public function test_history_lists_every_attempt_newest_first(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->fakeHealthy('https://one.example.test/api');
        $this->putJson('/api/super-admin/system-configuration', ['api_base_url' => 'https://one.example.test/api'])->assertOk();

        $this->getJson('/api/super-admin/system-configuration/history')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'validated')
            ->assertJsonPath('data.0.can_rollback', true);
    }

    public function test_a_company_admin_cannot_view_or_manage_system_configuration(): void
    {
        Sanctum::actingAs(User::factory()->companyAdmin(Company::factory()->create())->create());

        $this->getJson('/api/super-admin/system-configuration')->assertForbidden();
        $this->putJson('/api/super-admin/system-configuration', ['api_base_url' => 'https://x.test/api'])->assertForbidden();
    }

    public function test_a_conductor_cannot_access_system_configuration(): void
    {
        $company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->forCompany($company)->withRole('conductor')->create());

        $this->getJson('/api/super-admin/system-configuration')->assertForbidden();
    }

    public function test_meta_server_config_falls_back_to_the_platform_wide_default(): void
    {
        Company::factory()->create(['code' => 'PERJODA']);
        MobileAppSetting::factory()->create(['api_base_url' => null]);

        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->fakeHealthy('https://global.example.test/api');
        $this->putJson('/api/super-admin/system-configuration', ['api_base_url' => 'https://global.example.test/api'])->assertOk();

        // The meta endpoint is public/unauthenticated — it never reads the caller.
        $this->getJson('/api/meta/server-config?company=PERJODA')
            ->assertOk()
            ->assertJsonPath('data.api_base_url', 'https://global.example.test/api')
            ->assertJsonPath('data.config_version', 1);
    }
}
