<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class PinLoginTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private function conductorWithPin(Company $company, string $pin = '1234'): User
    {
        return User::factory()->forCompany($company)->withRole('conductor')->create([
            'email' => 'conductor@acme.test',
            'pin_hash' => Hash::make($pin),
        ]);
    }

    public function test_a_conductor_can_sign_in_with_their_pin(): void
    {
        $company = Company::factory()->create();
        $driver = Driver::factory()->for($company)->create();
        $this->conductorWithPin($company);

        $response = $this->postJson('/api/auth/pin-login', [
            'email' => 'conductor@acme.test', 'pin' => '1234', 'driver_code' => $driver->driver_code,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonPath('user.email', 'conductor@acme.test');
    }

    public function test_pin_login_rejects_an_incorrect_pin(): void
    {
        $company = Company::factory()->create();
        $this->conductorWithPin($company);

        $this->postJson('/api/auth/pin-login', ['email' => 'conductor@acme.test', 'pin' => '9999'])
            ->assertJsonValidationErrorFor('pin');
    }

    public function test_pin_login_rejects_a_non_conductor_account(): void
    {
        $company = Company::factory()->create();
        User::factory()->forCompany($company)->create([
            'email' => 'office@acme.test',
            'pin_hash' => Hash::make('1234'),
        ]);

        $this->postJson('/api/auth/pin-login', ['email' => 'office@acme.test', 'pin' => '1234'])
            ->assertJsonValidationErrorFor('pin');
    }

    public function test_pin_login_rejects_an_account_with_no_pin_set(): void
    {
        $company = Company::factory()->create();
        User::factory()->forCompany($company)->withRole('conductor')->create(['email' => 'nopin@acme.test']);

        $this->postJson('/api/auth/pin-login', ['email' => 'nopin@acme.test', 'pin' => '1234'])
            ->assertJsonValidationErrorFor('pin');
    }

    public function test_a_user_can_verify_their_own_pin_without_getting_a_new_token(): void
    {
        $company = Company::factory()->create();
        $user = $this->conductorWithPin($company);
        Sanctum::actingAs($user);

        $this->postJson('/api/auth/verify-pin', ['pin' => '1234'])
            ->assertOk()
            ->assertJsonPath('verified', true);

        $this->postJson('/api/auth/verify-pin', ['pin' => '0000'])
            ->assertJsonValidationErrorFor('pin');
    }

    public function test_a_user_can_set_their_own_pin(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->forCompany($company)->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/auth/pin', [
            'current_password' => 'password',
            'pin' => '4321',
            'pin_confirmation' => '4321',
        ])->assertOk()->assertJsonPath('has_pin', true);

        $this->assertTrue($user->fresh()->hasPin());
    }

    public function test_an_admin_can_set_a_conductors_pin_on_create(): void
    {
        $company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->companyAdmin($company)->create());
        $roleId = Role::query()->where('key', 'conductor')->value('id');

        $this->postJson('/api/company/users', [
            'name' => 'New Conductor', 'email' => 'newc@acme.test', 'password' => 'secret-password',
            'role_id' => $roleId, 'pin' => '5678',
        ])->assertCreated();

        $created = User::query()->where('email', 'newc@acme.test')->firstOrFail();
        $this->assertTrue($created->hasPin());
    }
}
