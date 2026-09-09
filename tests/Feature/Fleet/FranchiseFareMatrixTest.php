<?php

namespace Tests\Feature\Fleet;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Route;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class FranchiseFareMatrixTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
    }

    private function admin(): User
    {
        $u = User::factory()->companyAdmin($this->company)->create();
        Sanctum::actingAs($u);

        return $u;
    }

    public function test_franchise_crud_and_listing_shows_grid_counts(): void
    {
        $this->admin();

        $res = $this->postJson('/api/company/franchises', [
            'applicant_name' => 'Acme Transport Coop',
            'case_no' => 'CASE 2020-1',
        ])->assertCreated();

        $id = $res->json('data.id');
        $this->getJson('/api/company/franchises')
            ->assertOk()
            ->assertJsonPath('data.0.stops_count', 0)
            ->assertJsonPath('data.0.routes_count', 0);

        $this->assertDatabaseHas('franchises', ['id' => $id, 'company_id' => $this->company->id]);
    }

    public function test_stops_define_the_grid_axes_in_order(): void
    {
        $this->admin();
        $franchise = Franchise::factory()->for($this->company)->create();

        $this->putJson("/api/company/franchises/{$franchise->id}/stops", [
            'stops' => ['SM PALA-PALA', 'LANGKAAN', 'FCIE'],
        ])->assertOk()->assertJsonPath('data.0.name', 'SM PALA-PALA');

        $this->getJson("/api/company/franchises/{$franchise->id}/fare-matrix")
            ->assertOk()
            ->assertJsonPath('stops', ['SM PALA-PALA', 'LANGKAAN', 'FCIE'])
            ->assertJsonPath('cells', []);
    }

    public function test_saving_a_cell_creates_the_route_and_fare(): void
    {
        $this->admin();
        $franchise = Franchise::factory()->for($this->company)->withStops(['A', 'B', 'C'])->create();

        $this->putJson("/api/company/franchises/{$franchise->id}/fare-matrix/cell", [
            'origin' => 'A', 'destination' => 'C', 'amount' => 45,
        ])->assertOk()->assertJsonPath('cell.amount', 45);

        $route = Route::withoutGlobalScopes()->where('franchise_id', $franchise->id)->firstOrFail();
        $this->assertSame('A', $route->origin);
        $this->assertSame('C', $route->destination);
        $this->assertDatabaseHas('fare_matrix', ['route_id' => $route->id, 'amount' => 45.00]);

        // Grid now reports the cell.
        $this->getJson("/api/company/franchises/{$franchise->id}/fare-matrix")
            ->assertOk()
            ->assertJsonPath('cells.A.C.amount', 45);
    }

    public function test_clearing_a_cell_removes_the_fare_but_keeps_the_route(): void
    {
        $this->admin();
        $franchise = Franchise::factory()->for($this->company)->withStops(['A', 'B'])->create();

        $this->putJson("/api/company/franchises/{$franchise->id}/fare-matrix/cell", ['origin' => 'A', 'destination' => 'B', 'amount' => 20])->assertOk();
        $this->putJson("/api/company/franchises/{$franchise->id}/fare-matrix/cell", ['origin' => 'A', 'destination' => 'B', 'amount' => null])->assertOk();

        $route = Route::withoutGlobalScopes()->where('franchise_id', $franchise->id)->firstOrFail();
        $this->assertDatabaseHas('routes', ['id' => $route->id]);
        $this->assertDatabaseMissing('fare_matrix', ['route_id' => $route->id]);
    }

    public function test_origin_and_destination_must_differ(): void
    {
        $this->admin();
        $franchise = Franchise::factory()->for($this->company)->withStops(['A', 'B'])->create();

        $this->putJson("/api/company/franchises/{$franchise->id}/fare-matrix/cell", ['origin' => 'A', 'destination' => 'A', 'amount' => 10])
            ->assertJsonValidationErrorFor('destination');
    }

    public function test_discount_override_is_set_and_cleared(): void
    {
        $this->admin();
        $franchise = Franchise::factory()->for($this->company)->withStops(['A', 'B'])->create();

        $this->putJson("/api/company/franchises/{$franchise->id}/fare-matrix/cell", ['origin' => 'A', 'destination' => 'B', 'amount' => 40])->assertOk();

        $this->putJson("/api/company/franchises/{$franchise->id}/fare-matrix/cell", [
            'origin' => 'A', 'destination' => 'B', 'amount' => 40, 'discounted_amount' => 32, 'touch_discount' => true,
        ])->assertOk()->assertJsonPath('cell.discounted_amount', 32);

        $this->putJson("/api/company/franchises/{$franchise->id}/fare-matrix/cell", [
            'origin' => 'A', 'destination' => 'B', 'amount' => 40, 'discounted_amount' => null, 'touch_discount' => true,
        ])->assertOk()->assertJsonPath('cell.discounted_amount', null);
    }

    public function test_discount_cannot_exceed_base_fare(): void
    {
        $this->admin();
        $franchise = Franchise::factory()->for($this->company)->withStops(['A', 'B'])->create();

        $this->putJson("/api/company/franchises/{$franchise->id}/fare-matrix/cell", [
            'origin' => 'A', 'destination' => 'B', 'amount' => 30, 'discounted_amount' => 45, 'touch_discount' => true,
        ])->assertJsonValidationErrorFor('discounted_amount');
    }

    public function test_a_stop_with_a_fare_cannot_be_removed(): void
    {
        $this->admin();
        $franchise = Franchise::factory()->for($this->company)->withStops(['A', 'B', 'C'])->create();
        $this->putJson("/api/company/franchises/{$franchise->id}/fare-matrix/cell", ['origin' => 'A', 'destination' => 'B', 'amount' => 20])->assertOk();

        $this->putJson("/api/company/franchises/{$franchise->id}/stops", ['stops' => ['A', 'C']])
            ->assertJsonValidationErrorFor('stops');
    }

    public function test_force_removes_a_stop_and_clears_its_fares(): void
    {
        $this->admin();
        $franchise = Franchise::factory()->for($this->company)->withStops(['A', 'B', 'C'])->create();
        $this->putJson("/api/company/franchises/{$franchise->id}/fare-matrix/cell", ['origin' => 'A', 'destination' => 'B', 'amount' => 20])->assertOk();

        $this->putJson("/api/company/franchises/{$franchise->id}/stops", ['stops' => ['A', 'C'], 'force' => true])
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertDatabaseMissing('routes', ['franchise_id' => $franchise->id, 'origin' => 'A', 'destination' => 'B']);
    }

    public function test_a_stop_can_be_removed_once_its_fares_are_cleared(): void
    {
        $this->admin();
        $franchise = Franchise::factory()->for($this->company)->withStops(['A', 'B', 'C'])->create();

        // Price then clear the A→B cell.
        $this->putJson("/api/company/franchises/{$franchise->id}/fare-matrix/cell", ['origin' => 'A', 'destination' => 'B', 'amount' => 20])->assertOk();
        $this->putJson("/api/company/franchises/{$franchise->id}/fare-matrix/cell", ['origin' => 'A', 'destination' => 'B', 'amount' => null])->assertOk();

        $this->putJson("/api/company/franchises/{$franchise->id}/stops", ['stops' => ['A', 'C']])
            ->assertOk()
            ->assertJsonPath('data.0.name', 'A')
            ->assertJsonPath('data.1.name', 'C');

        // The now-fare-less phantom route is gone too.
        $this->assertDatabaseMissing('routes', ['franchise_id' => $franchise->id, 'origin' => 'A', 'destination' => 'B']);
    }

    public function test_another_companys_grid_is_not_reachable(): void
    {
        $other = Company::factory()->create();
        $foreign = Franchise::factory()->for($other)->withStops(['A', 'B'])->create();
        $this->admin();

        $this->getJson("/api/company/franchises/{$foreign->id}/fare-matrix")->assertNotFound();
        $this->putJson("/api/company/franchises/{$foreign->id}/fare-matrix/cell", ['origin' => 'A', 'destination' => 'B', 'amount' => 10])
            ->assertNotFound();
    }

    public function test_office_can_read_the_grid_but_not_edit_cells_or_stops(): void
    {
        $franchise = Franchise::factory()->for($this->company)->withStops(['A', 'B'])->create();
        Sanctum::actingAs(User::factory()->forCompany($this->company)->create()); // office role

        $this->getJson("/api/company/franchises/{$franchise->id}/fare-matrix")->assertOk();
        $this->putJson("/api/company/franchises/{$franchise->id}/fare-matrix/cell", ['origin' => 'A', 'destination' => 'B', 'amount' => 10])
            ->assertForbidden();
        $this->putJson("/api/company/franchises/{$franchise->id}/stops", ['stops' => ['A', 'B', 'C']])
            ->assertForbidden();
    }
}
