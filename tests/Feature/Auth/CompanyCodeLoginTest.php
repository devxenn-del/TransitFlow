<?php

namespace Tests\Feature\Auth;

use App\Actions\SaveFareCell;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Franchise;
use App\Models\Terminal;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

/**
 * Company Code sign-in: every company account signs in with its company's
 * code and must belong to that company; the issued token is bound to that
 * company for the rest of the session. See AuthController::companyFromCode()
 * / ensureAccountBelongsToCompany() and ResolveCompanyContext.
 */
class CompanyCodeLoginTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $companyA;

    private Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyA = Company::factory()->create(['code' => 'ALPHA']);
        $this->companyB = Company::factory()->create(['code' => 'BRAVO']);
    }

    private function adminOf(Company $company, string $email = 'admin@alpha.test'): User
    {
        return User::factory()->companyAdmin($company)->create(['email' => $email]);
    }

    private function login(array $payload)
    {
        return $this->postJson('/api/auth/login', $payload + ['password' => 'password']);
    }

    // Scenario A
    public function test_a_user_signs_in_with_their_own_company_code(): void
    {
        $user = $this->adminOf($this->companyA);

        $response = $this->login(['company_code' => 'ALPHA', 'email' => $user->email])
            ->assertOk()
            ->assertJsonPath('user.company.code', 'ALPHA');

        $token = PersonalAccessToken::findToken($response->json('token'));
        $this->assertContains("company:{$this->companyA->id}", $token->abilities);
    }

    public function test_the_company_code_is_case_and_whitespace_insensitive(): void
    {
        $user = $this->adminOf($this->companyA);

        $this->login(['company_code' => '  alpha ', 'email' => $user->email])->assertOk();
    }

    // Scenario B
    public function test_valid_credentials_under_another_companys_code_are_rejected_like_wrong_credentials(): void
    {
        $user = $this->adminOf($this->companyA);

        $this->login(['company_code' => 'BRAVO', 'email' => $user->email])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', __('auth.failed'))
            ->assertJsonMissingPath('token');

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'auth.company_mismatch',
            'company_id' => $this->companyA->id,
            'subject_id' => $user->id,
        ]);
    }

    public function test_pin_login_under_another_companys_code_is_rejected(): void
    {
        $conductor = User::factory()->forCompany($this->companyA)->withRole('conductor')
            ->create(['email' => 'c@alpha.test', 'pin_hash' => Hash::make('1234')]);
        $driver = Driver::factory()->for($this->companyA)->create();

        $this->postJson('/api/auth/pin-login', [
            'company_code' => 'BRAVO', 'email' => $conductor->email, 'pin' => '1234', 'driver_code' => $driver->driver_code,
        ])->assertStatus(422)->assertJsonPath('errors.pin.0', 'Incorrect PIN.');
    }

    public function test_an_unknown_company_code_is_rejected_before_the_credentials(): void
    {
        $user = $this->adminOf($this->companyA);

        $this->login(['company_code' => 'NOPE', 'email' => $user->email])
            ->assertStatus(422)
            ->assertJsonPath('errors.company_code.0', 'Invalid Company Code.');
    }

    public function test_a_badly_formatted_company_code_is_rejected(): void
    {
        $this->login(['company_code' => 'AL PHA!', 'email' => 'admin@alpha.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('company_code');
    }

    public function test_a_company_account_must_send_its_company_code(): void
    {
        $user = $this->adminOf($this->companyA);

        $this->login(['email' => $user->email])
            ->assertStatus(422)
            ->assertJsonPath('errors.company_code.0', 'Enter your Company Code.');
    }

    // Scenario H
    public function test_an_inactive_company_cannot_be_signed_in_to(): void
    {
        $company = Company::factory()->suspended()->create(['code' => 'SUSP']);
        $user = $this->adminOf($company, 'admin@susp.test');

        $this->login(['company_code' => 'SUSP', 'email' => $user->email])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('company_code');
    }

    public function test_an_inactive_account_cannot_sign_in(): void
    {
        $user = User::factory()->companyAdmin($this->companyA)->inactive()->create(['email' => 'off@alpha.test']);

        $this->login(['company_code' => 'ALPHA', 'email' => $user->email])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }

    // Scenario I
    public function test_a_platform_admin_signs_in_without_a_company_code_but_not_with_one(): void
    {
        $admin = User::factory()->superAdmin()->create(['email' => 'root@platform.test']);

        $this->login(['email' => $admin->email])->assertOk();

        $this->login(['company_code' => 'ALPHA', 'email' => $admin->email])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }

    public function test_a_token_issued_for_one_company_stops_working_if_the_account_moves(): void
    {
        $user = $this->adminOf($this->companyA);
        $token = $this->login(['company_code' => 'ALPHA', 'email' => $user->email])->assertOk()->json('token');

        $user->forceFill(['company_id' => $this->companyB->id])->save();

        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    // Scenario C — a driver code from another company (see also DriverCodeLoginTest)
    public function test_a_conductor_cannot_verify_another_companys_driver(): void
    {
        User::factory()->forCompany($this->companyA)->withRole('conductor')->create(['email' => 'c@alpha.test']);
        $foreignDriver = Driver::factory()->for($this->companyB)->create();

        $this->login(['company_code' => 'ALPHA', 'email' => 'c@alpha.test', 'driver_code' => $foreignDriver->driver_code])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('driver_code');
    }

    /** A conductor of company A, clocked in, with a priced route — for the trip scenarios below. */
    private function conductorReadyToStart(): User
    {
        $conductor = User::factory()->forCompany($this->companyA)->withRole('conductor')->create();
        Attendance::factory()->create(['company_id' => $this->companyA->id, 'user_id' => $conductor->id]);
        Terminal::factory()->for($this->companyA)->create(['name' => 'MAIN', 'boarding_mode' => 'Both']);
        $route = Franchise::factory()->for($this->companyA)->withStops(['MAIN', 'HUB'])->create();
        app(SaveFareCell::class)->handle($route, 'MAIN', 'HUB', 20);

        return $conductor;
    }

    // Scenario D
    public function test_a_trip_cannot_use_another_companys_bus_even_if_linked_to_the_conductor(): void
    {
        $conductor = $this->conductorReadyToStart();
        $foreignBus = Bus::factory()->for($this->companyB)->create();
        $conductor->buses()->attach($foreignBus); // tampered assignment
        Sanctum::actingAs($conductor);

        $this->postJson('/api/conductor/trips', [
            'bus_id' => $foreignBus->id,
            'driver_id' => Driver::factory()->for($this->companyA)->create()->id,
            'origin' => 'MAIN', 'coverage_origin' => 'MAIN', 'coverage_destination' => 'HUB',
        ])->assertJsonValidationErrorFor('bus_id');
    }

    public function test_a_trip_cannot_use_another_companys_driver(): void
    {
        $conductor = $this->conductorReadyToStart();
        $bus = Bus::factory()->for($this->companyA)->create();
        $conductor->buses()->attach($bus);
        Sanctum::actingAs($conductor);

        $this->postJson('/api/conductor/trips', [
            'bus_id' => $bus->id,
            'driver_id' => Driver::factory()->for($this->companyB)->create()->id,
            'origin' => 'MAIN', 'coverage_origin' => 'MAIN', 'coverage_destination' => 'HUB',
        ])->assertJsonValidationErrorFor('driver_id');
    }

    // Scenarios F and G
    public function test_another_companys_trip_stops_and_fares_are_not_reachable_by_id(): void
    {
        $foreignTrip = Trip::factory()->create(['company_id' => $this->companyB->id]);
        Sanctum::actingAs(User::factory()->forCompany($this->companyA)->withRole('conductor')->create());

        $this->getJson("/api/conductor/trips/{$foreignTrip->id}/stops")->assertNotFound();
        $this->getJson("/api/conductor/trips/{$foreignTrip->id}/fares")->assertNotFound();
    }

    public function test_a_company_header_naming_another_company_is_refused(): void
    {
        Sanctum::actingAs($this->adminOf($this->companyA));

        $this->withHeader('X-Company-Id', (string) $this->companyB->id)
            ->getJson('/api/auth/me')
            ->assertForbidden();
    }
}
