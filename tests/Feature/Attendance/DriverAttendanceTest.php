<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Driver;
use App\Models\DriverAttendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

/**
 * Drivers clock in and out with the conductor who verified them at sign-in
 * (App\Support\DriverAttendanceSync), and the web Attendance page filters both
 * lists (App\Support\AttendanceFilters). Real login + bearer token — the
 * driver comes from the token (Driver::verifiedIdForToken), like TripDriverLockTest.
 */
class DriverAttendanceTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private User $conductor;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->conductor = User::factory()->forCompany($this->company)->withRole('conductor')->create([
            'email' => 'conductor@acme.test',
            'pin_hash' => Hash::make('1234'),
        ]);
        $this->driver = Driver::factory()->for($this->company)->create(['name' => 'Juan Dela Cruz']);
    }

    private function login(Driver $driver, string $email = 'conductor@acme.test'): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/auth/login', [
            'company_code' => $this->company->code, 'email' => $email, 'password' => 'password', 'driver_code' => $driver->driver_code,
        ])->assertOk()->json('token');
    }

    private function toggle(string $token): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/conductor/attendance/toggle', ['source' => 'app'])->assertCreated();
    }

    private function asAdmin(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withoutToken();
        Sanctum::actingAs(User::factory()->companyAdmin($this->company)->create());
    }

    public function test_clocking_in_clocks_in_the_driver_verified_at_sign_in(): void
    {
        $this->toggle($this->login($this->driver));

        $row = DriverAttendance::query()->withoutGlobalScopes()->sole();
        $this->assertSame($this->driver->id, $row->driver_id);
        $this->assertSame($this->conductor->id, $row->conductor_id);
        $this->assertTrue($row->isOpen());
        $this->assertSame('app', $row->clock_in_source);
    }

    public function test_clocking_out_clocks_the_driver_out_too(): void
    {
        $token = $this->login($this->driver);
        $this->toggle($token);
        $this->toggle($token);

        $this->assertFalse(DriverAttendance::query()->withoutGlobalScopes()->sole()->isOpen());
    }

    public function test_ending_the_shift_clocks_the_driver_out(): void
    {
        $token = $this->login($this->driver);
        $this->toggle($token);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/conductor/shift-end/confirm', ['pin' => '1234'])->assertOk();

        $this->assertFalse(DriverAttendance::query()->withoutGlobalScopes()->sole()->isOpen());
    }

    public function test_signing_in_again_with_another_driver_mid_shift_swaps_the_driver(): void
    {
        $this->toggle($this->login($this->driver));
        $second = Driver::factory()->for($this->company)->create();

        $this->login($second);

        $rows = DriverAttendance::query()->withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertFalse($rows[0]->isOpen());
        $this->assertSame('auto', $rows[0]->clock_out_source);
        $this->assertSame($second->id, $rows[1]->driver_id);
        $this->assertTrue($rows[1]->isOpen());
        // Same conductor shift throughout.
        $this->assertSame($rows[0]->conductor_attendance_id, $rows[1]->conductor_attendance_id);

        // Signing in again with the same driver changes nothing.
        $this->login($second);
        $this->assertSame(2, DriverAttendance::query()->withoutGlobalScopes()->count());
    }

    public function test_signing_in_while_off_shift_does_not_clock_the_driver_in(): void
    {
        $this->login($this->driver);

        $this->assertSame(0, DriverAttendance::query()->withoutGlobalScopes()->count());
    }

    public function test_a_driver_is_only_on_shift_with_one_conductor_at_a_time(): void
    {
        $this->toggle($this->login($this->driver));
        User::factory()->forCompany($this->company)->withRole('conductor')->create(['email' => 'second@acme.test']);

        $this->toggle($this->login($this->driver, 'second@acme.test'));

        $this->assertSame(1, DriverAttendance::query()->withoutGlobalScopes()->open()->count());
    }

    public function test_force_closing_the_conductors_shift_closes_the_driver_and_one_driver_can_be_closed_alone(): void
    {
        $this->toggle($this->login($this->driver));
        $this->asAdmin();

        $driverRow = DriverAttendance::query()->sole();
        $this->postJson("/api/company/driver-attendance/{$driverRow->id}/close", ['note' => 'Went home sick'])
            ->assertOk()
            ->assertJsonPath('data.is_open', false)
            ->assertJsonPath('data.closed_note', 'Went home sick');
        $this->assertTrue(Attendance::query()->sole()->isOpen()); // conductor still on shift

        // Fresh shift + driver, then force-close the conductor.
        Attendance::query()->update(['clock_out_at' => now()]);
        $this->toggle($this->login($this->driver));
        $this->asAdmin();
        $period = Attendance::query()->open()->sole();
        $this->postJson("/api/company/attendance/{$period->id}/close", ['note' => 'Forgot to clock out'])->assertOk();

        $this->assertSame(0, DriverAttendance::query()->open()->count());
    }

    public function test_both_lists_filter_by_date_range_status_source_person_and_search(): void
    {
        $other = Driver::factory()->for($this->company)->create(['name' => 'Pedro Penduko', 'driver_code' => 'DRV-PEDRO']);
        $mine = Attendance::factory()->create([
            'company_id' => $this->company->id, 'user_id' => $this->conductor->id,
            'clock_in_at' => now()->subDays(3), 'clock_out_at' => now()->subDays(3)->addHours(8), 'clock_in_source' => 'web',
        ]);
        $today = Attendance::factory()->create([
            'company_id' => $this->company->id, 'user_id' => $this->conductor->id,
            'clock_in_at' => now()->subHour(), 'clock_out_at' => null, 'clock_in_source' => 'app',
        ]);
        DriverAttendance::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'driver_id' => $this->driver->id, 'conductor_id' => $this->conductor->id,
            'conductor_attendance_id' => $mine->id, 'clock_in_at' => $mine->clock_in_at, 'clock_out_at' => $mine->clock_out_at, 'clock_in_source' => 'web',
        ]);
        DriverAttendance::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'driver_id' => $other->id, 'conductor_id' => $this->conductor->id,
            'conductor_attendance_id' => $today->id, 'clock_in_at' => $today->clock_in_at, 'clock_in_source' => 'app',
        ]);
        $this->asAdmin();

        $ids = fn (string $url) => collect($this->getJson($url)->assertOk()->json('data'))->pluck('id')->all();

        // Conductors list
        $this->assertSame([$today->id], $ids('/api/company/attendance?status=on_shift'));
        $this->assertSame([$mine->id], $ids('/api/company/attendance?status=clocked_out'));
        $this->assertSame([$mine->id], $ids('/api/company/attendance?source=web'));
        $this->assertSame([$mine->id], $ids('/api/company/attendance?from='.now()->subDays(4)->toDateString().'&to='.now()->subDays(2)->toDateString()));
        $this->assertSame([$today->id, $mine->id], $ids('/api/company/attendance?conductor_id='.$this->conductor->id));
        $this->assertSame([], $ids('/api/company/attendance?q=nobody'));
        // Each shift names its driver(s).
        $this->assertSame('Pedro Penduko', $this->getJson('/api/company/attendance?status=on_shift')->json('data.0.drivers.0.name'));

        // Drivers list
        $this->assertSame(['Pedro Penduko'], collect($this->getJson('/api/company/driver-attendance?status=on_shift')->json('data'))->pluck('driver.name')->all());
        $this->assertSame(['Juan Dela Cruz'], collect($this->getJson('/api/company/driver-attendance?driver_id='.$this->driver->id)->json('data'))->pluck('driver.name')->all());
        $this->assertSame(['Pedro Penduko'], collect($this->getJson('/api/company/driver-attendance?q=PEDRO')->json('data'))->pluck('driver.name')->all());
        $this->assertSame(['Juan Dela Cruz'], collect($this->getJson('/api/company/driver-attendance?source=web&to='.now()->subDays(2)->toDateString())->json('data'))->pluck('driver.name')->all());
    }
}
