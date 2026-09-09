<?php

namespace Tests\Feature\Fleet;

use App\Models\Company;
use App\Models\FareMatrix;
use App\Models\Route;
use App\Models\Terminal;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class FleetModuleTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->other = Company::factory()->create();
    }

    private function actAsAdmin(): User
    {
        $user = User::factory()->companyAdmin($this->company)->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /* ---------- Buses ---------- */

    public function test_a_bus_is_created_with_capacity_model_and_vehicle_type(): void
    {
        $this->actAsAdmin();

        $response = $this->postJson('/api/company/buses', [
            'bus_number' => 'BUS-001', 'plate_number' => 'ABC-123',
            'capacity' => 45, 'model' => 'Hino RK', 'vehicle_type' => 'diesel',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.capacity', 45)
            ->assertJsonPath('data.model', 'Hino RK')
            ->assertJsonPath('data.vehicle_type', 'diesel');
    }

    public function test_vehicle_type_defaults_to_diesel_and_rejects_an_unknown_value(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/company/buses', ['bus_number' => 'BUS-002', 'plate_number' => 'DEF-456'])
            ->assertCreated()
            ->assertJsonPath('data.vehicle_type', 'diesel');

        $this->postJson('/api/company/buses', [
            'bus_number' => 'BUS-003', 'plate_number' => 'GHI-789', 'vehicle_type' => 'hydrogen',
        ])->assertJsonValidationErrorFor('vehicle_type');
    }

    /* ---------- Terminals ---------- */

    public function test_company_admin_creates_and_lists_terminals(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/company/terminals', ['name' => 'MAIN TERMINAL', 'boarding_mode' => 'Both'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'MAIN TERMINAL');

        $this->getJson('/api/company/terminals')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_terminal_name_is_unique_per_company_only(): void
    {
        Terminal::factory()->for($this->other)->create(['name' => 'SHARED']);
        $this->actAsAdmin();

        $this->postJson('/api/company/terminals', ['name' => 'SHARED'])->assertCreated();
        $this->postJson('/api/company/terminals', ['name' => 'SHARED'])->assertJsonValidationErrorFor('name');
    }

    public function test_another_companys_terminal_is_not_reachable(): void
    {
        $foreign = Terminal::factory()->for($this->other)->create();
        $this->actAsAdmin();

        $this->getJson("/api/company/terminals/{$foreign->id}")->assertNotFound();
        $this->patchJson("/api/company/terminals/{$foreign->id}", ['status' => 'Inactive'])->assertNotFound();
    }

    /* ---------- Routes + fare matrix ---------- */

    public function test_route_is_created_with_a_default_name_and_no_fare(): void
    {
        $this->actAsAdmin();

        $res = $this->postJson('/api/company/routes', ['origin' => 'A', 'destination' => 'B'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'A - B')
            ->assertJsonPath('data.is_priced', false);

        $this->assertDatabaseHas('routes', ['id' => $res->json('data.id'), 'company_id' => $this->company->id]);
    }

    public function test_duplicate_origin_destination_is_rejected_per_company(): void
    {
        Route::factory()->for($this->other)->create(['origin' => 'A', 'destination' => 'B']);
        $this->actAsAdmin();

        $this->postJson('/api/company/routes', ['origin' => 'A', 'destination' => 'B'])->assertCreated();
        $this->postJson('/api/company/routes', ['origin' => 'A', 'destination' => 'B'])
            ->assertJsonValidationErrorFor('destination');
    }

    public function test_origin_and_destination_must_differ(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/company/routes', ['origin' => 'A', 'destination' => 'A'])
            ->assertJsonValidationErrorFor('destination');
    }

    // Fare-matrix write behaviour (one fare per route, discount ≤ base,
    // cross-company route rejection) is covered by FranchiseFareMatrixTest —
    // the grid is the only path that writes fares.

    public function test_priced_scope_only_returns_active_routes_with_an_active_fare(): void
    {
        $priced = Route::factory()->for($this->company)->priced(45)->create();
        Route::factory()->for($this->company)->create(); // unpriced
        $inactiveFare = Route::factory()->for($this->company)->priced(30)->create();
        $inactiveFare->fareMatrix->update(['status' => 'Inactive']);

        $this->company->withoutRelations();
        app(CompanyContext::class)->set($this->company->id);

        $ids = Route::query()->priced()->pluck('id');
        $this->assertTrue($ids->contains($priced->id));
        $this->assertCount(1, $ids);
    }

    /* ---------- Permission gating ---------- */

    public function test_a_user_without_routes_view_is_blocked(): void
    {
        $user = User::factory()->forCompany($this->company)->withoutPermissions()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/company/routes')->assertForbidden();
        $this->getJson('/api/company/terminals')->assertForbidden();
        $this->getJson('/api/company/franchises')->assertForbidden();
    }

    public function test_office_role_can_view_fleet_but_not_edit(): void
    {
        // office default grants terminals.view / routes.view / farematrix.view, no create.
        Sanctum::actingAs(User::factory()->forCompany($this->company)->create());

        $this->getJson('/api/company/routes')->assertOk();
        $this->postJson('/api/company/routes', ['origin' => 'X', 'destination' => 'Y'])->assertForbidden();
    }
}
