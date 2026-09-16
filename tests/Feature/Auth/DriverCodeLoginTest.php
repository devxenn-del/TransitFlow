<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

/**
 * Driver Code — a conductor login must additionally supply the Driver Code
 * of the one driver they're paired with (users.driver_id). See
 * AuthController::driverCodeFailure().
 */
class DriverCodeLoginTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private function conductorWithDriver(Company $company, ?string $driverCode = null): array
    {
        $driver = Driver::factory()->for($company)->create($driverCode !== null ? ['driver_code' => $driverCode] : []);
        $conductor = User::factory()->forCompany($company)->withRole('conductor')
            ->create(['email' => 'conductor@acme.test', 'driver_id' => $driver->id]);

        return [$conductor, $driver];
    }

    public function test_driver_code_is_auto_generated_on_create(): void
    {
        $driver = Driver::factory()->for(Company::factory()->create())->create();

        $this->assertNotNull($driver->driver_code);
        $this->assertMatchesRegularExpression('/^DR-\d{4,}$/', $driver->driver_code);
    }

    public function test_a_conductor_can_log_in_with_the_correct_driver_code(): void
    {
        $company = Company::factory()->create();
        [, $driver] = $this->conductorWithDriver($company);

        $this->postJson('/api/auth/login', [
            'email' => 'conductor@acme.test', 'password' => 'password', 'driver_code' => $driver->driver_code,
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'conductor@acme.test')
            ->assertJsonPath('user.driver.driver_code', $driver->driver_code);
    }

    public function test_driver_code_comparison_is_case_and_whitespace_insensitive(): void
    {
        $company = Company::factory()->create();
        [, $driver] = $this->conductorWithDriver($company, 'DR-9001');

        $this->postJson('/api/auth/login', [
            'email' => 'conductor@acme.test', 'password' => 'password', 'driver_code' => '  dr-9001  ',
        ])->assertOk();
    }

    public function test_a_conductor_missing_the_driver_code_field_gets_a_specific_message(): void
    {
        $company = Company::factory()->create();
        $this->conductorWithDriver($company);

        $this->postJson('/api/auth/login', ['email' => 'conductor@acme.test', 'password' => 'password'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('driver_code')
            ->assertJsonPath('errors.driver_code.0', 'Driver Code is required.');
    }

    public function test_a_conductor_with_the_wrong_driver_code_gets_the_same_generic_error_as_a_bad_password(): void
    {
        $company = Company::factory()->create();
        $this->conductorWithDriver($company, 'DR-1111');

        $wrongCode = $this->postJson('/api/auth/login', [
            'email' => 'conductor@acme.test', 'password' => 'password', 'driver_code' => 'DR-9999',
        ])->assertStatus(422);

        $wrongPassword = $this->postJson('/api/auth/login', [
            'email' => 'conductor@acme.test', 'password' => 'wrong', 'driver_code' => 'DR-1111',
        ])->assertStatus(422);

        // Same field, same message — a wrong code can't be told apart from a wrong password.
        $this->assertSame('email', array_key_first($wrongCode->json('errors')));
        $this->assertSame($wrongPassword->json('errors.email.0'), $wrongCode->json('errors.email.0'));
    }

    public function test_one_conductors_driver_code_does_not_unlock_another_conductors_account(): void
    {
        $company = Company::factory()->create();
        [, $driverA] = $this->conductorWithDriver($company, 'DR-AAAA');

        $driverB = Driver::factory()->for($company)->create(['driver_code' => 'DR-BBBB']);
        User::factory()->forCompany($company)->withRole('conductor')
            ->create(['email' => 'conductorB@acme.test', 'driver_id' => $driverB->id]);

        // Conductor A's credentials + Conductor B's driver's code.
        $this->postJson('/api/auth/login', [
            'email' => 'conductor@acme.test', 'password' => 'password', 'driver_code' => 'DR-BBBB',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_a_conductor_with_no_driver_paired_cannot_log_in_even_with_a_real_code(): void
    {
        $company = Company::factory()->create();
        User::factory()->forCompany($company)->withRole('conductor')->create(['email' => 'unpaired@acme.test']);
        $someDriver = Driver::factory()->for($company)->create();

        $this->postJson('/api/auth/login', [
            'email' => 'unpaired@acme.test', 'password' => 'password', 'driver_code' => $someDriver->driver_code,
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_non_conductor_accounts_do_not_need_a_driver_code(): void
    {
        $company = Company::factory()->create();
        User::factory()->companyAdmin($company)->create(['email' => 'admin@acme.test']);

        $this->postJson('/api/auth/login', ['email' => 'admin@acme.test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@acme.test');
    }

    public function test_an_admin_can_pair_a_conductor_with_a_driver_on_create(): void
    {
        $company = Company::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs(User::factory()->companyAdmin($company)->create());
        $driver = Driver::factory()->for($company)->create();
        $roleId = \App\Models\Role::query()->where('key', 'conductor')->value('id');

        $res = $this->postJson('/api/company/users', [
            'name' => 'New Conductor', 'email' => 'newc@acme.test', 'password' => 'secret-password',
            'role_id' => $roleId, 'driver_id' => $driver->id,
        ])->assertCreated();

        $this->assertSame($driver->id, $res->json('data.driver_id'));
    }

    public function test_creating_a_driver_rejects_a_duplicate_driver_code_within_the_same_company(): void
    {
        $company = Company::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs(User::factory()->companyAdmin($company)->create());
        Driver::factory()->for($company)->create(['driver_code' => 'DR-DUPE']);

        $this->postJson('/api/company/drivers', ['name' => 'Another Driver', 'driver_code' => 'DR-DUPE'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('driver_code');
    }
}
