<?php

namespace Tests\Feature\MultiCompany;

use App\Models\Company;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class DeviceMonitoringTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private User $admin;

    private User $conductor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->companyAdmin($this->company)->create();
        $this->conductor = User::factory()->forCompany($this->company)->withRole('conductor')->create();
    }

    public function test_a_conductor_registers_a_device_and_it_stamps_the_heartbeat(): void
    {
        Sanctum::actingAs($this->conductor);

        $this->postJson('/api/conductor/devices/register', [
            'device_uuid' => 'DEV-ABC-123',
            'platform' => 'android',
            'model' => 'Redmi 12',
            'app_version' => '1.2.0',
        ])->assertCreated()->assertJsonPath('data.device_uuid', 'DEV-ABC-123');

        $this->assertDatabaseHas('devices', [
            'company_id' => $this->company->id,
            'user_id' => $this->conductor->id,
            'device_uuid' => 'DEV-ABC-123',
            'model' => 'Redmi 12',
        ]);

        $device = Device::query()->first();
        $this->assertNotNull($device->last_seen_at);
        $this->assertNotNull($device->registered_at);
    }

    public function test_re_registering_the_same_uuid_updates_the_row_and_refreshes_last_seen(): void
    {
        $original = Device::factory()->for($this->company)->create([
            'user_id' => $this->conductor->id,
            'device_uuid' => 'DEV-1',
            'app_version' => '1.0.0',
            'registered_at' => Carbon::parse('2026-09-01 08:00:00'),
            'last_seen_at' => Carbon::parse('2026-09-01 08:00:00'),
        ]);

        Sanctum::actingAs($this->conductor);

        $this->postJson('/api/conductor/devices/register', [
            'device_uuid' => 'DEV-1', 'app_version' => '1.3.0',
        ])->assertOk();

        $this->assertDatabaseCount('devices', 1);
        $fresh = $original->fresh();
        $this->assertSame('1.3.0', $fresh->app_version);
        $this->assertTrue($fresh->last_seen_at->greaterThan(Carbon::parse('2026-09-01 08:00:00')));
        $this->assertEquals('2026-09-01 08:00:00', $fresh->registered_at->toDateTimeString());
    }

    public function test_admin_lists_devices_and_can_filter_by_platform_and_offline(): void
    {
        Device::factory()->for($this->company)->create(['user_id' => $this->conductor->id, 'platform' => 'android']);
        Device::factory()->for($this->company)->create(['user_id' => $this->conductor->id, 'platform' => 'ios']);
        Device::factory()->for($this->company)->offline()->create(['user_id' => $this->conductor->id, 'platform' => 'android']);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/company/devices')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/company/devices?platform=android')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/company/devices?stale=1')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_online_flag_reflects_last_seen(): void
    {
        Device::factory()->for($this->company)->create(['user_id' => $this->conductor->id, 'last_seen_at' => now()]);
        Device::factory()->for($this->company)->offline()->create(['user_id' => $this->conductor->id]);

        Sanctum::actingAs($this->admin);

        $flags = collect($this->getJson('/api/company/devices')->json('data'))->pluck('online')->sort()->values();
        $this->assertEquals([false, true], $flags->all());
    }

    public function test_admin_deregisters_a_device(): void
    {
        $device = Device::factory()->for($this->company)->create(['user_id' => $this->conductor->id]);

        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/company/devices/{$device->id}")->assertNoContent();
        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
    }

    public function test_devices_are_company_scoped(): void
    {
        $other = Company::factory()->create();
        $otherDevice = Device::factory()->for($other)->create();

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/company/devices')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/company/devices/{$otherDevice->id}")->assertNotFound();
    }

    public function test_permission_gates(): void
    {
        $device = Device::factory()->for($this->company)->create(['user_id' => $this->conductor->id]);
        $office = User::factory()->forCompany($this->company)->withRole('office')->create();

        // office can view but not delete
        Sanctum::actingAs($office);
        $this->getJson('/api/company/devices')->assertOk();
        $this->deleteJson("/api/company/devices/{$device->id}")->assertForbidden();

        // conductor can register but not view the admin list
        Sanctum::actingAs($this->conductor);
        $this->getJson('/api/company/devices')->assertForbidden();
        $this->postJson('/api/conductor/devices/register', ['device_uuid' => 'X'])->assertCreated();

        // a non-conductor cannot register
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/conductor/devices/register', ['device_uuid' => 'Y'])->assertForbidden();
    }
}
