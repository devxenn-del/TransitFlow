<?php

namespace Tests\Feature\Operations;

use App\Models\Attendance;
use App\Models\Bus;
use App\Models\CharterBooking;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Franchise;
use App\Models\PassengerType;
use App\Models\Terminal;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

/**
 * Special trips run approved charter bookings (App\Actions\StartTrip::startCharter):
 * the conductor sees their approved charters, starts one (its bus and its
 * driver — who must be the one verified at sign-in), records departure
 * (mark-on-trip) and arrival (End Trip), and the booking follows the trip.
 * No passenger tickets. Real login + bearer token, like TripDriverLockTest.
 */
class SpecialTripTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private User $conductor;

    private Bus $bus;

    private Driver $driver;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->conductor = User::factory()->forCompany($this->company)->withRole('conductor')->create(['email' => 'conductor@acme.test']);
        Attendance::factory()->create(['company_id' => $this->company->id, 'user_id' => $this->conductor->id]);

        $this->bus = Bus::factory()->for($this->company)->create();
        $this->conductor->buses()->attach($this->bus);

        Terminal::factory()->for($this->company)->create(['name' => 'MAIN', 'boarding_mode' => 'Both']);
        Franchise::factory()->for($this->company)->withStops(['MAIN', 'HUB'])->create()
            ->routes()->create(['company_id' => $this->company->id, 'origin' => 'MAIN', 'destination' => 'HUB', 'name' => 'MAIN - HUB', 'status' => 'Active'])
            ->fareMatrix()->create(['company_id' => $this->company->id, 'amount' => 25, 'status' => 'Active']);

        $this->driver = Driver::factory()->for($this->company)->create();
        $this->token = $this->login($this->driver);
    }

    private function login(Driver $driver): string
    {
        return $this->postJson('/api/auth/login', [
            'company_code' => $this->company->code, 'email' => 'conductor@acme.test', 'password' => 'password', 'driver_code' => $driver->driver_code,
        ])->assertOk()->json('token');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function charter(array $overrides = []): CharterBooking
    {
        return CharterBooking::factory()->create(array_merge([
            'company_id' => $this->company->id,
            'bus_id' => $this->bus->id,
            'driver_id' => $this->driver->id,
            'conductor_id' => $this->conductor->id,
            'status' => 'Approved',
            'departure_at' => now()->addMinutes(30),
            'return_at' => now()->addHours(8),
            'pickup_point' => 'Parish Church',
            'destination' => 'Manaoag',
        ], $overrides));
    }

    private function startSpecial(CharterBooking $charter)
    {
        return $this->withToken($this->token)->postJson('/api/conductor/trips', [
            'trip_type' => 'Special',
            'charter_booking_id' => $charter->id,
            'driver_id' => $this->driver->id,
        ]);
    }

    public function test_the_conductor_sees_only_their_own_approved_charters_without_the_money(): void
    {
        $mine = $this->charter();
        $this->charter(['status' => 'Pending', 'departure_at' => now()->addDays(2), 'return_at' => now()->addDays(2)->addHours(5)]);
        $other = User::factory()->forCompany($this->company)->withRole('conductor')->create();
        $this->charter(['conductor_id' => $other->id, 'departure_at' => now()->addDays(3), 'return_at' => now()->addDays(3)->addHours(5)]);

        $res = $this->withToken($this->token)->getJson('/api/conductor/charters')->assertOk();

        $this->assertSame([$mine->id], collect($res->json('data'))->pluck('id')->all());
        $this->assertArrayNotHasKey('agreed_price', $res->json('data.0'));
        $this->assertArrayNotHasKey('ledger', $res->json('data.0'));
    }

    public function test_a_special_trip_requires_a_charter(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/conductor/trips', ['trip_type' => 'Special', 'driver_id' => $this->driver->id])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('charter_booking_id');
    }

    public function test_starting_a_charter_runs_the_full_lifecycle_and_the_booking_follows(): void
    {
        $charter = $this->charter();

        $res = $this->startSpecial($charter)->assertCreated()
            ->assertJsonPath('data.trip_type', 'Special')
            ->assertJsonPath('data.status', 'Departure')
            ->assertJsonPath('data.charter.reference', $charter->fresh()->reference)
            ->assertJsonPath('data.charter.destination', 'Manaoag');
        $trip = Trip::query()->findOrFail($res->json('data.id'));
        $this->assertSame($this->bus->id, $trip->bus_id);
        $this->assertSame('InProgress', $charter->fresh()->status);
        $this->assertSame($trip->id, $charter->fresh()->trip_id);

        // Departure = left the pickup point; arrival = End Trip.
        $this->withToken($this->token)->postJson('/api/conductor/trips/mark-on-trip')->assertOk()->assertJsonPath('data.status', 'OnTrip');
        $this->withToken($this->token)->postJson('/api/conductor/trips/end')->assertOk()->assertJsonPath('data.status', 'Arrived');

        $this->assertSame('Completed', $charter->fresh()->status);
        $this->assertNotNull($trip->fresh()->marked_on_trip_at);
        $this->assertNotNull($trip->fresh()->ended_at);
    }

    public function test_cancelling_the_trip_puts_the_charter_back_to_approved(): void
    {
        $charter = $this->charter();
        $this->startSpecial($charter)->assertCreated();

        $this->withToken($this->token)->postJson('/api/conductor/trips/cancel', ['reason' => 'Bus broke down'])->assertOk();

        $this->assertSame('Approved', $charter->fresh()->status);
        $this->assertNull($charter->fresh()->trip_id);
    }

    public function test_a_charter_that_is_not_approved_or_not_mine_cannot_be_started(): void
    {
        $this->startSpecial($this->charter(['status' => 'Pending']))
            ->assertStatus(422)->assertJsonValidationErrorFor('charter_booking_id');

        $other = User::factory()->forCompany($this->company)->withRole('conductor')->create();
        $this->startSpecial($this->charter(['conductor_id' => $other->id, 'departure_at' => now()->addMinutes(40)]))
            ->assertStatus(422)->assertJsonValidationErrorFor('charter_booking_id');
    }

    public function test_a_charter_cannot_be_started_long_before_its_departure(): void
    {
        $this->startSpecial($this->charter(['departure_at' => now()->addDays(2), 'return_at' => now()->addDays(2)->addHours(6)]))
            ->assertStatus(422)->assertJsonValidationErrorFor('charter_booking_id');
    }

    public function test_the_booked_driver_must_be_the_one_verified_at_sign_in(): void
    {
        $bookedDriver = Driver::factory()->for($this->company)->create(['name' => 'Juan Dela Cruz']);

        $this->startSpecial($this->charter(['driver_id' => $bookedDriver->id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.driver_id.0', "This charter is assigned to driver Juan Dela Cruz — sign out and sign in with that driver's code.");
    }

    public function test_a_special_trip_does_not_issue_passenger_tickets(): void
    {
        $tripId = $this->startSpecial($this->charter())->assertCreated()->json('data.id');
        $type = PassengerType::factory()->for($this->company)->create();

        $this->withToken($this->token)
            ->postJson('/api/conductor/tickets', [
                'trip_id' => $tripId,
                'passenger_type_id' => $type->id,
                'manual_amount' => 50,
                'boarding_type' => 'Pickup',
                'payment_method' => 'Cash',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('trip');
    }

    public function test_a_regular_trip_cannot_take_a_crew_booked_for_a_charter_under_way(): void
    {
        $charter = $this->charter(['departure_at' => now()->subMinutes(10)]);

        $this->withToken($this->token)
            ->postJson('/api/conductor/trips', [
                'bus_id' => $this->bus->id,
                'driver_id' => $this->driver->id,
                'origin' => 'MAIN',
                'coverage_origin' => 'MAIN',
                'coverage_destination' => 'HUB',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('bus_id');

        $this->assertStringContainsString($charter->fresh()->reference, $this->withToken($this->token)
            ->postJson('/api/conductor/trips', [
                'bus_id' => $this->bus->id, 'driver_id' => $this->driver->id,
                'origin' => 'MAIN', 'coverage_origin' => 'MAIN', 'coverage_destination' => 'HUB',
            ])->json('errors.bus_id.0'));
    }

    public function test_the_remittance_report_shows_the_charter_instead_of_a_route(): void
    {
        $charter = $this->charter();
        $tripId = $this->startSpecial($charter)->assertCreated()->json('data.id');

        $lines = collect($this->withToken($this->token)
            ->getJson("/api/conductor/trips/{$tripId}/receipt/remittance")
            ->assertOk()
            ->json('data.lines'));

        $this->assertSame('SPECIAL', $lines->firstWhere('label', 'Trip Type')['value']);
        $this->assertSame($charter->fresh()->reference, $lines->firstWhere('label', 'Charter #')['value']);
        $this->assertNotNull($lines->first(fn ($l) => ($l['text'] ?? '') === 'Destination: Manaoag'));
        $this->assertNull($lines->first(fn ($l) => str_starts_with($l['text'] ?? '', 'Coverage:')));
    }
}
