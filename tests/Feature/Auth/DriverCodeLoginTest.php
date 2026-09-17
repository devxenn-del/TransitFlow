<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

/**
 * Driver Code — a conductor is not paired with one fixed driver (they may
 * work with a different bus/driver from one shift to the next), so login
 * accepts any Active driver's code belonging to the SAME company as the
 * signing-in account. See AuthController::verifyDriverCode() and
 * App\Actions\StartTrip (which then requires that same driver for every
 * trip on this session).
 */
class DriverCodeLoginTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private function conductor(Company $company): User
    {
        return User::factory()->forCompany($company)->withRole('conductor')
            ->create(['email' => 'conductor@acme.test']);
    }

    public function test_driver_code_is_auto_generated_on_create(): void
    {
        $driver = Driver::factory()->for(Company::factory()->create())->create();

        $this->assertNotNull($driver->driver_code);
        $this->assertMatchesRegularExpression('/^DR-\d{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $driver->driver_code);
    }

    public function test_a_conductor_can_log_in_with_any_active_drivers_code_in_their_company(): void
    {
        $company = Company::factory()->create();
        $this->conductor($company);
        $driver = Driver::factory()->for($company)->create();

        $this->postJson('/api/auth/login', [
            'email' => 'conductor@acme.test', 'password' => 'password', 'driver_code' => $driver->driver_code,
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'conductor@acme.test')
            ->assertJsonPath('driver.id', $driver->id)
            ->assertJsonPath('driver.driver_code', $driver->driver_code);
    }

    public function test_a_conductor_is_not_tied_to_any_particular_driver(): void
    {
        $company = Company::factory()->create();
        $this->conductor($company);
        $driverA = Driver::factory()->for($company)->create();
        $driverB = Driver::factory()->for($company)->create();

        $this->postJson('/api/auth/login', [
            'email' => 'conductor@acme.test', 'password' => 'password', 'driver_code' => $driverA->driver_code,
        ])->assertOk()->assertJsonPath('driver.id', $driverA->id);

        // A completely different driver's code works just as well.
        $this->postJson('/api/auth/login', [
            'email' => 'conductor@acme.test', 'password' => 'password', 'driver_code' => $driverB->driver_code,
        ])->assertOk()->assertJsonPath('driver.id', $driverB->id);
    }

    public function test_driver_code_comparison_is_case_and_whitespace_insensitive(): void
    {
        $company = Company::factory()->create();
        $this->conductor($company);
        $driver = Driver::factory()->for($company)->create(['driver_code' => 'DR-9001']);

        $this->postJson('/api/auth/login', [
            'email' => 'conductor@acme.test', 'password' => 'password', 'driver_code' => '  dr-9001  ',
        ])->assertOk();
    }

    public function test_a_conductor_missing_the_driver_code_field_gets_a_specific_message(): void
    {
        $company = Company::factory()->create();
        $this->conductor($company);

        $this->postJson('/api/auth/login', ['email' => 'conductor@acme.test', 'password' => 'password'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('driver_code')
            ->assertJsonPath('errors.driver_code.0', 'Driver Code is required.');
    }

    public function test_an_unrecognized_driver_code_is_rejected_with_a_specific_message(): void
    {
        $company = Company::factory()->create();
        $this->conductor($company);

        $this->postJson('/api/auth/login', [
            'email' => 'conductor@acme.test', 'password' => 'password', 'driver_code' => 'DR-9999',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('driver_code')
            ->assertJsonPath('errors.driver_code.0', 'That driver code was not recognized.');
    }

    public function test_a_driver_code_from_another_company_is_rejected(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->conductor($companyA);
        $otherCompanysDriver = Driver::factory()->for($companyB)->create();

        $this->postJson('/api/auth/login', [
            'email' => 'conductor@acme.test', 'password' => 'password', 'driver_code' => $otherCompanysDriver->driver_code,
        ])->assertStatus(422)->assertJsonValidationErrorFor('driver_code');
    }

    public function test_an_inactive_drivers_code_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->conductor($company);
        $driver = Driver::factory()->for($company)->create(['status' => 'Inactive']);

        $this->postJson('/api/auth/login', [
            'email' => 'conductor@acme.test', 'password' => 'password', 'driver_code' => $driver->driver_code,
        ])->assertStatus(422)->assertJsonValidationErrorFor('driver_code');
    }

    public function test_non_conductor_accounts_do_not_need_a_driver_code(): void
    {
        $company = Company::factory()->create();
        User::factory()->companyAdmin($company)->create(['email' => 'admin@acme.test']);

        $this->postJson('/api/auth/login', ['email' => 'admin@acme.test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@acme.test')
            ->assertJsonPath('driver', null);
    }

    public function test_the_verified_driver_is_stamped_on_the_token_and_returned_by_me(): void
    {
        $company = Company::factory()->create();
        $this->conductor($company);
        $driver = Driver::factory()->for($company)->create();

        $login = $this->postJson('/api/auth/login', [
            'email' => 'conductor@acme.test', 'password' => 'password', 'driver_code' => $driver->driver_code,
        ])->assertOk();

        $token = PersonalAccessToken::findToken($login->json('token'));
        $this->assertContains("driver:{$driver->id}", $token->abilities);

        $this->withToken($login->json('token'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('driver.id', $driver->id);
    }

    public function test_editing_a_driver_with_no_code_and_leaving_it_blank_generates_one(): void
    {
        $company = Company::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs(User::factory()->companyAdmin($company)->create());
        $driver = Driver::factory()->for($company)->create(['driver_code' => null]);

        $updated = $this->putJson("/api/company/drivers/{$driver->id}", ['name' => $driver->name])
            ->assertOk()
            ->json('data');

        $this->assertNotNull($updated['driver_code']);
        $this->assertMatchesRegularExpression('/^DR-\d{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $updated['driver_code']);
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
