<?php

namespace Tests\Feature\Charters;

use App\Models\Bus;
use App\Models\CharterBooking;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

/**
 * Charter Bookings, office side (App\Http\Controllers\Api\Company\CharterBookingController):
 * booking, crew conflicts, approval, and the charter's own money ledger.
 */
class CharterBookingTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private Bus $bus;

    private Driver $driver;

    private User $conductor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->bus = Bus::factory()->create(['company_id' => $this->company->id]);
        $this->driver = Driver::factory()->create(['company_id' => $this->company->id]);
        $this->conductor = User::factory()->forCompany($this->company)->withRole('conductor')->create();
        Sanctum::actingAs(User::factory()->companyAdmin($this->company)->create());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'client_name' => 'St. Mary Parish',
            'contact_person' => 'Fr. Jose',
            'contact_number' => '09171234567',
            'purpose' => 'Pilgrimage',
            'pickup_point' => 'Parish Church',
            'destination' => 'Manaoag',
            'departure_at' => now()->addDays(5)->setTime(6, 0)->toIso8601String(),
            'return_at' => now()->addDays(5)->setTime(20, 0)->toIso8601String(),
            'passenger_count' => 45,
            'agreed_price' => 18000,
            'bus_id' => $this->bus->id,
            'driver_id' => $this->driver->id,
            'conductor_id' => $this->conductor->id,
        ], $overrides);
    }

    private function booking(array $overrides = []): CharterBooking
    {
        return CharterBooking::factory()->create(array_merge([
            'company_id' => $this->company->id,
            'bus_id' => $this->bus->id,
            'driver_id' => $this->driver->id,
            'conductor_id' => $this->conductor->id,
        ], $overrides));
    }

    public function test_a_booking_is_created_pending_with_a_reference(): void
    {
        $res = $this->postJson('/api/company/charters', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'Pending')
            ->assertJsonPath('data.client_name', 'St. Mary Parish')
            ->assertJsonPath('data.bus.id', $this->bus->id)
            ->assertJsonPath('data.ledger.amount_due', 18000)
            ->assertJsonPath('data.ledger.balance', 18000);

        $this->assertMatchesRegularExpression('/^CHR-\d{6}$/', $res->json('data.reference'));
    }

    public function test_the_return_must_be_after_the_departure(): void
    {
        $this->postJson('/api/company/charters', $this->payload([
            'return_at' => now()->addDays(5)->setTime(5, 0)->toIso8601String(),
        ]))->assertStatus(422)->assertJsonValidationErrorFor('return_at');
    }

    public function test_an_overlapping_booking_for_the_same_bus_driver_or_conductor_is_refused(): void
    {
        $this->booking([
            'departure_at' => now()->addDays(5)->setTime(10, 0),
            'return_at' => now()->addDays(5)->setTime(14, 0),
        ]);

        $this->postJson('/api/company/charters', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bus_id', 'driver_id', 'conductor_id']);
    }

    public function test_a_back_to_back_or_cancelled_booking_is_not_a_conflict(): void
    {
        // Ends exactly when the new one starts.
        $this->booking(['departure_at' => now()->addDays(5)->setTime(1, 0), 'return_at' => now()->addDays(5)->setTime(6, 0)]);
        $this->booking(['departure_at' => now()->addDays(5)->setTime(8, 0), 'return_at' => now()->addDays(5)->setTime(9, 0), 'status' => 'Cancelled']);

        $this->postJson('/api/company/charters', $this->payload())->assertCreated();
    }

    public function test_a_crew_out_on_a_live_trip_is_busy_for_a_window_covering_now(): void
    {
        Trip::factory()->for($this->company)->create(['bus_id' => $this->bus->id, 'status' => 'OnTrip']);

        $this->postJson('/api/company/charters', $this->payload([
            'departure_at' => now()->subHour()->toIso8601String(),
            'return_at' => now()->addHours(4)->toIso8601String(),
        ]))->assertStatus(422)->assertJsonValidationErrorFor('bus_id');
    }

    public function test_availability_flags_the_taken_crew(): void
    {
        $existing = $this->booking();
        $freeBus = Bus::factory()->create(['company_id' => $this->company->id]);

        $res = $this->getJson('/api/company/charters/availability?'.http_build_query([
            'departure_at' => $existing->departure_at->toIso8601String(),
            'return_at' => $existing->return_at->toIso8601String(),
        ]))->assertOk();

        $buses = collect($res->json('buses'))->keyBy('id');
        $this->assertStringContainsString($existing->fresh()->reference, $buses[$this->bus->id]['busy']);
        $this->assertNull($buses[$freeBus->id]['busy']);

        // Editing that same booking doesn't count itself.
        $res = $this->getJson('/api/company/charters/availability?'.http_build_query([
            'departure_at' => $existing->departure_at->toIso8601String(),
            'return_at' => $existing->return_at->toIso8601String(),
            'except' => $existing->id,
        ]))->assertOk();
        $this->assertNull(collect($res->json('buses'))->keyBy('id')[$this->bus->id]['busy']);
    }

    public function test_approving_needs_the_approve_permission(): void
    {
        $booking = $this->booking();

        Sanctum::actingAs(User::factory()->forCompany($this->company)->withRole('office')->create());
        $this->postJson("/api/company/charters/{$booking->id}/approve")->assertForbidden();

        Sanctum::actingAs(User::factory()->companyAdmin($this->company)->create());
        $this->postJson("/api/company/charters/{$booking->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'Approved');
    }

    public function test_changing_an_approved_bookings_crew_or_times_sends_it_back_for_approval(): void
    {
        $booking = $this->booking(['status' => 'Approved', 'approved_at' => now()]);
        $otherBus = Bus::factory()->create(['company_id' => $this->company->id]);

        $this->putJson("/api/company/charters/{$booking->id}", $this->payload([
            'departure_at' => $booking->departure_at->toIso8601String(),
            'return_at' => $booking->return_at->toIso8601String(),
            'bus_id' => $otherBus->id,
        ]))->assertOk()->assertJsonPath('data.status', 'Pending');
    }

    public function test_a_rejected_booking_cannot_be_edited_or_approved(): void
    {
        $booking = $this->booking();
        $this->postJson("/api/company/charters/{$booking->id}/reject", ['reason' => 'No bus that day'])
            ->assertOk()->assertJsonPath('data.status', 'Rejected');

        $this->postJson("/api/company/charters/{$booking->id}/approve")->assertStatus(422);
        $this->putJson("/api/company/charters/{$booking->id}", $this->payload())->assertStatus(422);
    }

    public function test_the_ledger_tracks_charges_payments_refunds_and_expenses_separately(): void
    {
        $booking = $this->booking(['agreed_price' => 15000]);

        $this->postJson("/api/company/charters/{$booking->id}/payments", ['type' => 'Deposit', 'amount' => 5000, 'method' => 'Cash'])->assertOk();
        $this->postJson("/api/company/charters/{$booking->id}/charges", ['kind' => 'Charge', 'category' => 'Overtime', 'amount' => 1000])->assertOk();
        $this->postJson("/api/company/charters/{$booking->id}/charges", ['kind' => 'Expense', 'category' => 'Fuel', 'amount' => 4200])->assertOk();
        $this->postJson("/api/company/charters/{$booking->id}/payments", ['type' => 'Payment', 'amount' => 12000, 'method' => 'Bank', 'reference_no' => 'BDO-1'])->assertOk();
        $res = $this->postJson("/api/company/charters/{$booking->id}/payments", ['type' => 'Refund', 'amount' => 1000, 'method' => 'Cash'])->assertOk();

        $ledger = $res->json('data.ledger');
        $this->assertEquals(16000, $ledger['amount_due']);   // 15000 + 1000 overtime
        $this->assertEquals(16000, $ledger['amount_paid']);  // 5000 + 12000 - 1000
        $this->assertEquals(0, $ledger['balance']);
        $this->assertEquals(4200, $ledger['expenses']);
        $this->assertEquals(11800, $ledger['net_income']);   // 16000 - 4200
        $this->assertCount(3, $res->json('data.payments'));
        $this->assertCount(2, $res->json('data.charges'));
    }

    public function test_settling_needs_a_completed_trip_and_a_zero_balance_then_locks_the_money(): void
    {
        $booking = $this->booking(['status' => 'Approved', 'agreed_price' => 10000]);
        $this->postJson("/api/company/charters/{$booking->id}/settle")->assertStatus(422); // not completed yet

        $booking->update(['status' => 'Completed']);
        $this->postJson("/api/company/charters/{$booking->id}/settle")
            ->assertStatus(422)->assertJsonValidationErrorFor('balance'); // still owes 10000

        $this->postJson("/api/company/charters/{$booking->id}/payments", ['type' => 'Payment', 'amount' => 10000, 'method' => 'Cash'])
            ->assertJsonPath('data.ledger.settleable', true);
        $this->postJson("/api/company/charters/{$booking->id}/settle")->assertOk()->assertJsonPath('data.status', 'Settled');

        $this->postJson("/api/company/charters/{$booking->id}/charges", ['kind' => 'Expense', 'category' => 'Toll', 'amount' => 300])
            ->assertStatus(422);
    }

    public function test_charter_money_never_touches_the_trips_ticket_remittance(): void
    {
        $trip = Trip::factory()->for($this->company)->create(['trip_type' => 'Special', 'status' => 'Arrived']);
        $booking = $this->booking(['status' => 'Completed', 'trip_id' => $trip->id]);
        $trip->update(['charter_booking_id' => $booking->id]);
        $this->postJson("/api/company/charters/{$booking->id}/payments", ['type' => 'Payment', 'amount' => 15000, 'method' => 'Cash'])->assertOk();

        $this->assertSame(0.0, (float) $trip->fresh()->collectedAmount());
    }

    public function test_another_companys_booking_is_not_found(): void
    {
        $other = Company::factory()->create();
        $foreign = CharterBooking::factory()->create([
            'company_id' => $other->id,
            'bus_id' => Bus::factory()->create(['company_id' => $other->id])->id,
            'driver_id' => Driver::factory()->create(['company_id' => $other->id])->id,
            'conductor_id' => User::factory()->forCompany($other)->withRole('conductor')->create()->id,
        ]);

        $this->getJson("/api/company/charters/{$foreign->id}")->assertNotFound();
    }
}
