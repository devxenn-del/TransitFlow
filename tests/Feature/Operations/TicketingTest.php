<?php

namespace Tests\Feature\Operations;

use App\Models\Company;
use App\Models\FareMatrix;
use App\Models\Franchise;
use App\Models\PassengerType;
use App\Models\PassengerTypeArticle;
use App\Models\Route;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class TicketingTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private User $conductor;

    private Trip $trip;

    private Route $route;

    private PassengerType $regular;

    private PassengerType $senior;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();

        $this->conductor = User::factory()->forCompany($this->company)->withRole('conductor')->create();
        Sanctum::actingAs($this->conductor);

        $franchise = Franchise::factory()->for($this->company)->withStops(['A', 'B'])->create();
        $this->route = Route::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'franchise_id' => $franchise->id,
            'origin' => 'A', 'destination' => 'B', 'name' => 'A - B', 'status' => 'Active',
        ]);
        FareMatrix::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'route_id' => $this->route->id, 'amount' => 45, 'status' => 'Active']);

        $this->regular = PassengerType::factory()->for($this->company)->create(['name' => 'Regular', 'discount_percent' => 0]);
        $this->senior = PassengerType::factory()->for($this->company)->discounted(20)->create(['name' => 'Senior']);

        $this->trip = Trip::factory()->for($this->company)->create([
            'conductor_id' => $this->conductor->id, 'status' => 'Departure',
            'coverage_origin' => 'A', 'coverage_destination' => 'B',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function issue(array $overrides = [])
    {
        return $this->postJson('/api/conductor/tickets', array_merge([
            'passenger_type_id' => $this->regular->id,
            'route_id' => $this->route->id,
            'boarding_type' => 'Terminal',
            'payment_method' => 'Cash',
        ], $overrides));
    }

    public function test_regular_fare_comes_from_the_matrix_and_ignores_a_client_supplied_fare(): void
    {
        $this->issue(['fare' => 999])
            ->assertCreated()
            ->assertJsonPath('tickets.0.fare', 45)
            ->assertJsonPath('total_fare', 45);
    }

    public function test_senior_gets_the_percentage_discount(): void
    {
        // 45 * (1 - 0.20) = 36
        $this->issue(['passenger_type_id' => $this->senior->id])
            ->assertCreated()
            ->assertJsonPath('tickets.0.fare', 36);
    }

    public function test_a_route_discounted_amount_override_wins_for_discounted_passengers(): void
    {
        $this->route->fareMatrix->update(['discounted_amount' => 30]);

        $this->issue(['passenger_type_id' => $this->senior->id])->assertJsonPath('tickets.0.fare', 30);
        // Regular is unaffected by the override.
        $this->issue(['passenger_type_id' => $this->regular->id])->assertJsonPath('tickets.0.fare', 45);
    }

    public function test_manual_amount_type_with_an_article_uses_the_preset(): void
    {
        $articles = PassengerType::factory()->for($this->company)->manualAmount()->create(['name' => 'Articles Sales']);
        PassengerTypeArticle::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'passenger_type_id' => $articles->id, 'label' => 'Student', 'amount' => 10, 'status' => 'Active',
        ]);

        $this->issue(['passenger_type_id' => $articles->id, 'route_id' => null, 'article_label' => 'Student', 'manual_amount' => 999])
            ->assertCreated()
            ->assertJsonPath('tickets.0.fare', 10)
            ->assertJsonPath('tickets.0.article_label', 'Student');
    }

    public function test_manual_amount_type_without_articles_uses_the_typed_amount_but_not_zero(): void
    {
        $free = PassengerType::factory()->for($this->company)->manualAmount()->create(['name' => 'Baggage']);

        $this->issue(['passenger_type_id' => $free->id, 'route_id' => null, 'manual_amount' => 0])
            ->assertJsonValidationErrorFor('manual_amount');
        $this->issue(['passenger_type_id' => $free->id, 'route_id' => null, 'manual_amount' => 25])
            ->assertJsonPath('tickets.0.fare', 25);
    }

    public function test_terminal_boarding_is_blocked_once_the_trip_is_on_trip(): void
    {
        $this->trip->update(['status' => 'OnTrip']);

        $this->issue(['boarding_type' => 'Terminal'])->assertJsonValidationErrorFor('boarding_type');
        $this->issue(['boarding_type' => 'Pickup'])->assertCreated();
    }

    public function test_qr_payment_requires_a_six_character_reference(): void
    {
        $this->issue(['payment_method' => 'QR', 'qr_reference' => '123'])->assertJsonValidationErrorFor('qr_reference');
        $this->issue(['payment_method' => 'QR', 'qr_reference' => 'ABC123'])
            ->assertCreated()
            ->assertJsonPath('tickets.0.qr_reference', 'ABC123');
    }

    public function test_quantity_issues_multiple_tickets(): void
    {
        $this->issue(['quantity' => 3])
            ->assertCreated()
            ->assertJsonPath('count', 3)
            ->assertJsonPath('total_fare', 135); // 3 * 45
    }

    public function test_client_uuid_makes_issuance_idempotent(): void
    {
        $first = $this->issue(['client_uuid' => 'abc-123'])->assertCreated();
        $again = $this->issue(['client_uuid' => 'abc-123'])->assertCreated();

        $this->assertSame($first->json('tickets.0.id'), $again->json('tickets.0.id'));
        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_cannot_issue_without_a_live_trip(): void
    {
        $this->trip->update(['status' => 'Arrived']);
        $this->issue()->assertStatus(404);
    }
}
