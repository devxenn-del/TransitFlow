<?php

namespace Tests\Feature\Operations;

use App\Models\Bus;
use App\Models\Company;
use App\Models\Dispatch;
use App\Models\PassengerType;
use App\Models\Route;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

/**
 * BITS `receipt/conductor/*.php` — thermal receipt DTOs.
 */
class ReceiptTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private User $conductor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->withSettings()->create();
        $this->company->settings()->update([
            'receipt_org_name' => 'Acme Lines Inc.',
            'ticket_footer' => 'Ride safe, ride Acme.',
            'receipt_width_mm' => 58,
        ]);
        $this->conductor = User::factory()->forCompany($this->company)->withRole('conductor')->create();
    }

    private function ownTrip(array $overrides = []): Trip
    {
        return Trip::factory()->for($this->company)->create(array_merge([
            'conductor_id' => $this->conductor->id,
        ], $overrides));
    }

    /** @return list<string> every row label across every section */
    private function labels(array $doc): array
    {
        return collect($doc['sections'])->flatMap(fn ($s) => collect($s['rows'])->pluck('label'))->all();
    }

    /**
     * A BITS-layout receipt's label/value lines, keyed by label.
     *
     * @return Collection<string, array<string, string>>
     */
    private function lineRows(array $doc)
    {
        return collect($doc['lines'])->filter(fn ($l) => isset($l['label']))->keyBy('label');
    }

    /** @return list<string> a BITS-layout receipt's centred/plain/section texts, in order */
    private function lineTexts(array $doc): array
    {
        return collect($doc['lines'])->pluck('text')->filter()->values()->all();
    }

    public function test_departure_receipt_is_the_plain_opening_slip_before_leaving_the_terminal(): void
    {
        $trip = $this->ownTrip(['status' => 'Departure']);
        Sanctum::actingAs($this->conductor);

        $res = $this->getJson("/api/conductor/trips/{$trip->id}/receipt/departure")
            ->assertOk()
            ->assertJsonPath('data.kind', 'departure')
            ->assertJsonPath('data.title', 'DEPARTURE SUCCESSFULLY OPENED')
            ->assertJsonPath('data.org.name', 'Acme Lines Inc.')
            ->assertJsonPath('data.width_mm', 58)
            ->assertJsonPath('data.reference', $trip->reference);

        $this->assertNotContains('Total Value', $this->labels($res->json('data')));
    }

    public function test_departure_receipt_becomes_a_terminal_receipt_with_totals_after_marking_on_trip(): void
    {
        $trip = $this->ownTrip()->fresh();
        $trip->update(['status' => 'OnTrip', 'marked_on_trip_at' => now()]);
        Ticket::factory()->for($this->company)->create(['trip_id' => $trip->id, 'payment_method' => 'Cash', 'fare' => 45]);
        Ticket::factory()->for($this->company)->create(['trip_id' => $trip->id, 'payment_method' => 'QR', 'fare' => 15]);

        Sanctum::actingAs($this->conductor);

        $res = $this->getJson("/api/conductor/trips/{$trip->id}/receipt/departure")
            ->assertOk()
            ->assertJsonPath('data.title', 'TERMINAL RECEIPT')
            // BITS layout: title, then the org block.
            ->assertJsonPath('data.lines.0', ['type' => 'title', 'text' => 'TERMINAL RECEIPT'])
            ->assertJsonPath('data.lines.1.text', 'Acme Lines Inc.');

        $doc = $res->json('data');
        $rows = $this->lineRows($doc);
        $this->assertSame('₱60.00', $rows['Total Value']['value']);
        $this->assertSame('bold_row', $rows['Total Value']['type']);
        $this->assertSame('₱45.00', $rows['Total Cash']['value']);
        $this->assertSame('₱15.00', $rows['Total QR']['value']);
        $this->assertSame('2', $rows['Total Passenger']['value']);
        $this->assertSame(['TRIP INFO', 'OPENING SALE DATE/TIME'], collect($doc['lines'])->where('type', 'section')->pluck('text')->values()->all());
    }

    public function test_remittance_receipt_carries_the_full_bits_breakdown(): void
    {
        $trip = $this->ownTrip(['status' => 'Arrived', 'ended_at' => now(), 'remitted_amount' => 80]);
        Ticket::factory()->for($this->company)->count(2)->create(['trip_id' => $trip->id, 'fare' => 50, 'payment_method' => 'Cash']);
        Dispatch::factory()->forTrip($trip)->create(['amount' => 20]);

        Sanctum::actingAs($this->conductor);

        $res = $this->getJson("/api/conductor/trips/{$trip->id}/receipt/remittance")
            ->assertOk()
            ->assertJsonPath('data.kind', 'remittance')
            ->assertJsonPath('data.title', 'TRIP REMITTANCE REPORT');

        $doc = $res->json('data');
        $this->assertSame(['BUS INFO', 'REMITTANCE INFO', 'BREAKDOWN'], collect($doc['lines'])->where('type', 'section')->pluck('text')->values()->all());

        $rows = $this->lineRows($doc);
        $this->assertSame('20.00', $rows['Total Dispatch']['value']);
        $this->assertSame('80.00', $rows['Remitted Amount']['value']);
        $this->assertSame('80.00', $rows['Total Cash']['value']); // cash on hand: 100 sales - 20 dispatch
        $this->assertSame('2', $rows['Total Tickets']['value']);
        // TOTAL VALUE stays every sale, as the slip's highlighted figure.
        $this->assertSame('₱100.00', $rows['TOTAL VALUE']['value']);
        $this->assertSame('highlight_row', $rows['TOTAL VALUE']['type']);
        $this->assertSame('highlight_row', collect($doc['lines'])->last()['type']);
    }

    public function test_cancelled_trip_uses_the_trip_cancelled_slip(): void
    {
        $trip = $this->ownTrip(['status' => 'Cancelled', 'cancelled_at' => now(), 'ended_at' => now(), 'cancellation_reason' => 'bus breakdown']);
        Sanctum::actingAs($this->conductor);

        $res = $this->getJson("/api/conductor/trips/{$trip->id}/receipt/arrival")
            ->assertOk()
            ->assertJsonPath('data.title', 'TRIP CANCELLED');

        $rows = collect($res->json('data.sections'))->flatMap(fn ($s) => $s['rows'])->keyBy('label');
        $this->assertSame('bus breakdown', $rows['Reason']['value']);
        $this->assertContains('Amount To Remit', $this->labels($res->json('data')));
    }

    public function test_ticket_receipt_multiplies_by_quantity(): void
    {
        $trip = $this->ownTrip();
        $type = PassengerType::factory()->for($this->company)->create(['name' => 'Regular']);
        $route = Route::factory()->for($this->company)->create(['origin' => 'SM PALA-PALA', 'destination' => 'Langkaan']);
        $ticket = Ticket::factory()->for($this->company)->create([
            'trip_id' => $trip->id,
            'route_id' => $route->id,
            'passenger_type_id' => $type->id,
            'boarding_type' => 'Pickup',
            'fare' => 20,
            'payment_method' => 'Cash',
        ]);

        Sanctum::actingAs($this->conductor);

        $res = $this->getJson("/api/conductor/tickets/{$ticket->id}/receipt?qty=3")
            ->assertOk()
            ->assertJsonPath('data.kind', 'ticket')
            ->assertJsonPath('data.title', 'PASSENGER TICKET')
            // BITS layout: the org name is the ticket's title line.
            ->assertJsonPath('data.lines.0', ['type' => 'title', 'text' => 'Acme Lines Inc.']);

        $doc = $res->json('data');
        $rows = $this->lineRows($doc);
        $this->assertSame('TKT-'.str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT), $rows['Ticket ID']['value']);
        $this->assertSame('3 x ₱20.00', $rows['Qty']['value']);
        $this->assertSame('₱60.00', $rows['TOTAL']['value']);
        $this->assertSame('bold_row', $rows['TOTAL']['type']);
        // "ORIGIN TO DESTINATION", "TYPE • BOARDING • PAYMENT" centred lines, and the footer last.
        $this->assertContains('SM PALA-PALA TO LANGKAAN', $this->lineTexts($doc));
        $this->assertContains('REGULAR • PICK UP • CASH', $this->lineTexts($doc));
        $this->assertSame(['type' => 'footer', 'text' => 'Ride safe, ride Acme.'], collect($doc['lines'])->last());
    }

    public function test_dispatch_receipt_shows_the_location_and_payout(): void
    {
        $trip = $this->ownTrip();
        // A dispatch is recorded by location — stored in the barker_name column.
        $dispatch = Dispatch::factory()->forTrip($trip)->create(['barker_name' => 'Cubao Terminal', 'amount' => 35]);

        Sanctum::actingAs($this->conductor);

        $doc = $this->getJson("/api/conductor/dispatches/{$dispatch->id}/receipt")
            ->assertOk()
            ->assertJsonPath('data.title', 'DISPATCH RECEIPT')
            ->assertJsonPath('data.lines.0', ['type' => 'title', 'text' => 'DISPATCH RECEIPT'])
            ->json('data');

        $rows = $this->lineRows($doc);
        $this->assertSame('Cubao Terminal', $rows['Location']['value']);
        $this->assertSame('₱35.00', $rows['AMOUNT PAID']['value']);
        $this->assertSame('DSP-'.str_pad((string) $dispatch->id, 6, '0', STR_PAD_LEFT), $rows['Dispatch #']['value']);
        $this->assertArrayNotHasKey('Barker', $rows->all());
    }

    public function test_shift_summary_totals_todays_trips_for_the_conductors_active_buses(): void
    {
        $bus = Bus::factory()->for($this->company)->create(['status' => 'Active', 'bus_number' => 'BUS-USED']);
        $unusedBus = Bus::factory()->for($this->company)->create(['status' => 'Active', 'bus_number' => 'BUS-IDLE']);
        $this->conductor->buses()->attach([$bus->id, $unusedBus->id]);

        $t1 = $this->ownTrip(['bus_id' => $bus->id, 'bus_number' => $bus->bus_number, 'started_at' => now()->setTime(6, 0)]);
        $t2 = $this->ownTrip(['bus_id' => $bus->id, 'bus_number' => $bus->bus_number, 'started_at' => now()->setTime(9, 0)]);
        Ticket::factory()->for($this->company)->count(2)->create(['trip_id' => $t1->id, 'fare' => 25, 'payment_method' => 'Cash']);
        Ticket::factory()->for($this->company)->create(['trip_id' => $t2->id, 'fare' => 50, 'payment_method' => 'QR']);

        Sanctum::actingAs($this->conductor);

        $res = $this->getJson('/api/conductor/receipts/shift-summary')
            ->assertOk()
            ->assertJsonPath('data.kind', 'shift-summary')
            ->assertJsonPath('data.title', 'SHIFT SALES SUMMARY');

        $rows = collect($res->json('data.sections'))->flatMap(fn ($s) => $s['rows'])->keyBy('label');
        // Only the bus actually driven today shows here — not every bus this
        // conductor happens to be assigned to (BUS-IDLE was never used).
        $this->assertSame('BUS-USED', $rows['Bus #']['value']);
        $this->assertSame('100.00', $rows['Gross Sales:']['value']); // 2×25 + 50
        $this->assertSame('50.00', $rows['Cash']['value']);
        $this->assertSame('50.00', $rows['QR']['value']);
        $this->assertSame('100.00', $rows['NET SALES:']['value']);
    }

    public function test_a_conductor_cannot_print_another_conductors_trip(): void
    {
        $mate = User::factory()->forCompany($this->company)->withRole('conductor')->create();
        $trip = Trip::factory()->for($this->company)->create(['conductor_id' => $mate->id]);

        Sanctum::actingAs($this->conductor);

        $this->getJson("/api/conductor/trips/{$trip->id}/receipt/remittance")->assertForbidden();
    }

    public function test_an_unknown_receipt_kind_is_rejected(): void
    {
        $trip = $this->ownTrip();
        Sanctum::actingAs($this->conductor);

        $this->getJson("/api/conductor/trips/{$trip->id}/receipt/banana")->assertNotFound();
    }
}
