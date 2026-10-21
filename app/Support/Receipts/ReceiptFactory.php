<?php

namespace App\Support\Receipts;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Dispatch;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Support\TripRemittance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the BITS `receipt/conductor/*.php` thermal slips as
 * {@see ReceiptDocument} DTOs. Every money figure mirrors the legacy
 * layouts and docs/MIGRATION_MAP.md §4.3 verbatim.
 */
class ReceiptFactory
{
    public function departure(Trip $trip): ReceiptDocument
    {
        $trip->loadMissing(['driver', 'conductor']);

        // A "TERMINAL RECEIPT" is the reprint taken when the bus leaves the
        // terminal — it carries the terminal-phase totals, in BITS's layout.
        // Before that it is just the plain opening slip.
        if ($trip->marked_on_trip_at !== null) {
            return $this->terminalReceipt($trip);
        }

        return ReceiptDocument::make('departure', 'DEPARTURE SUCCESSFULLY OPENED', $this->brand($trip->company_id), $this->width($trip->company_id))
            ->reference($trip->reference)
            ->section('TRIP INFO', [
                'Trip #' => $trip->reference,
                'Origin' => $trip->coverage_origin,
                'Destination' => $trip->coverage_destination,
                'Departure Date' => $this->date($trip->started_at),
                'Departure Time' => $this->time($trip->started_at),
                'Driver' => $trip->driver?->name ?: '—',
                'Conductor' => $trip->conductor?->name ?: '—',
                'Bus Number' => $trip->bus_number ?: '—',
            ])
            ->section('OPENING SALE DATE/TIME', [
                'Printed' => $this->dateTime($trip->started_at),
            ]);
    }

    /**
     * BITS Conductor's Terminal Receipt (TicketingActivity::buildTerminalReceipt),
     * line for line. E-Wallet isn't a BITS payment method, so its row only
     * appears when there is some — it's always part of Total Value.
     */
    private function terminalReceipt(Trip $trip): ReceiptDocument
    {
        $byMethod = $this->byPaymentMethod($trip);
        $passengerCount = $trip->tickets()
            ->whereNull('refunded_at')
            ->whereNull('article_label')
            ->count();
        $totalValue = $byMethod['Cash'] + $byMethod['QR'] + $byMethod['E-Wallet'];

        $lines = [ReceiptLine::title('TERMINAL RECEIPT')];
        array_push($lines, ...$this->orgHeader($trip->company_id));
        array_push($lines,
            ReceiptLine::section('TRIP INFO'),
            ReceiptLine::row('Trip #', (string) $trip->reference),
            ReceiptLine::row('Origin', $trip->coverage_origin ?: '—'),
            ReceiptLine::row('Destination', $trip->coverage_destination ?: '—'),
            ReceiptLine::row('Date', now()->format('m-d-Y')),
            ReceiptLine::row('Time', now()->format('h:iA')),
            ReceiptLine::row('Driver', $trip->driver?->name ?: '—'),
            ReceiptLine::row('Conductor', $trip->conductor?->name ?: '—'),
            ReceiptLine::row('Bus Number', $trip->bus_number ?: '—'),
            ReceiptLine::row('Total Passenger', (string) $passengerCount),
            ReceiptLine::row('Total Cash', $this->peso($byMethod['Cash'])),
            ReceiptLine::row('Total QR', $this->peso($byMethod['QR'])),
        );
        if ($byMethod['E-Wallet'] > 0) {
            $lines[] = ReceiptLine::row('Total E-Wallet', $this->peso($byMethod['E-Wallet']));
        }
        array_push($lines,
            ReceiptLine::boldRow('Total Value', $this->peso($totalValue)),
            ReceiptLine::section('OPENING SALE DATE/TIME'),
            ReceiptLine::center($this->dateTime($trip->started_at)),
        );

        return ReceiptDocument::make('departure', 'TERMINAL RECEIPT', $this->brand($trip->company_id), $this->width($trip->company_id))
            ->reference($trip->reference)
            ->lines($lines);
    }

    public function arrival(Trip $trip): ReceiptDocument
    {
        $trip->loadMissing(['driver', 'conductor']);
        $r = TripRemittance::for($trip)->toArray();

        if ($trip->status === 'Cancelled') {
            return ReceiptDocument::make('arrival', 'TRIP CANCELLED', $this->brand($trip->company_id), $this->width($trip->company_id))
                ->reference($trip->reference)
                ->section('TRIP INFO', [
                    'Trip #' => $trip->reference,
                    'Origin' => $trip->coverage_origin,
                    'Destination' => $trip->coverage_destination,
                    'Cancelled Date' => $trip->cancelled_at ? $this->date($trip->cancelled_at) : '—',
                    'Cancelled Time' => $trip->cancelled_at ? $this->time($trip->cancelled_at) : '—',
                    'Driver' => $trip->driver?->name ?: '—',
                    'Conductor' => $trip->conductor?->name ?: '—',
                    'Bus Number' => $trip->bus_number ?: '—',
                    'Reason' => $trip->cancellation_reason ?: '—',
                ])
                ->section('PASSENGERS AFFECTED', [
                    'Ticket(s) issued' => (string) ($r['ticket_count'] + $r['refunded_count']),
                    'Passenger(s) affected' => (string) $r['refunded_count'],
                    'Refunded Amount' => $this->money($r['refunded']),
                    'Amount To Remit' => [$this->money($r['remitted_amount'] ?? 0), 'strong' => true, 'total' => true],
                ]);
        }

        $counts = $this->countsByBoarding($trip);
        $totalPassengers = $counts['Terminal'] + $counts['Pickup'];

        return ReceiptDocument::make('arrival', 'TRIP ARRIVAL RECEIPT', $this->brand($trip->company_id), $this->width($trip->company_id))
            ->reference($trip->reference)
            ->section('BUS INFO', [
                'Bus #' => [$trip->bus_number ?: '—', 'form' => true],
                'Route' => [trim(($trip->coverage_origin ?: '?').' - '.($trip->coverage_destination ?: '?')), 'form' => true],
                'Terminal' => [$trip->origin ?: '—', 'form' => true],
                'Driver' => [$trip->driver?->name ?: '—', 'form' => true],
                'Conductor' => [$trip->conductor?->name ?: '—', 'form' => true],
            ])
            ->section('REMITTANCE INFO', [
                'Total Passengers' => (string) $totalPassengers,
                '  Terminal Passengers' => (string) $counts['Terminal'],
                '  Pickup Passengers' => (string) $counts['Pickup'],
                '---1' => true,
                'Via Terminal' => $this->money($r['by_boarding_type']['Terminal']),
                'Via Pickup' => $this->money($r['by_boarding_type']['Pickup']),
                '---2' => true,
                'Total Cash' => $this->money($r['by_payment_method']['Cash']),
                'Total QR' => $this->money($r['by_payment_method']['QR']),
                'Total E-Wallet' => $this->money($r['by_payment_method']['E-Wallet']),
                'Total Dispatch' => $this->money($r['total_dispatch']),
                '---3' => true,
                'Remitted Amount' => $r['remitted_amount'] !== null ? $this->money($r['remitted_amount']) : 'Pending',
                'Total Balance' => $r['balance'] !== null ? $this->money($r['balance']) : '—',
                '---4' => true,
                'Total Ticket' => (string) ($r['ticket_count'] + $r['refunded_count']),
                'Total Articles' => (string) $r['article_count'],
                '---5' => true,
                'TOTAL VALUE' => [$this->money($r['collected']), 'strong' => true, 'total' => true],
            ])
            ->section(null, [
                'Date & Time' => [$this->dateTime(now()), 'form' => true],
                'Printed by' => [$trip->conductor?->name ?: '—', 'form' => true],
            ]);
    }

    /**
     * BITS Conductor's TRIP REMITTANCE REPORT (RemittanceActivity), line for
     * line: BUS INFO, REMITTANCE INFO, BREAKDOWN, then TOTAL VALUE as the
     * highlighted figure. Cash in BREAKDOWN is cash on hand — the dispatch
     * is paid out of cash sales — while TOTAL VALUE stays every sale.
     * Once a cashier has counted/approved it (web), those blocks follow too.
     */
    public function remittance(Trip $trip): ReceiptDocument
    {
        $trip->loadMissing(['driver', 'conductor', 'remittanceReceivedBy']);
        $r = TripRemittance::for($trip)->toArray();
        $counts = $this->countsByBoarding($trip);

        $lines = [ReceiptLine::title('TRIP REMITTANCE REPORT')];
        array_push($lines, ...$this->orgHeader($trip->company_id));
        array_push($lines,
            ReceiptLine::center($this->stamp(now())),

            ReceiptLine::section('BUS INFO'),
            ReceiptLine::row('Bus #', $trip->bus_number ?: '—'),
        );
        if ($trip->trip_type === 'Special') {
            // A Special trip (charter / hire) has no route or terminal.
            $lines[] = ReceiptLine::row('Trip Type', 'SPECIAL');
        } else {
            array_push($lines,
                // Coverage can run past one 32-column line, so it wraps as plain text.
                ReceiptLine::plain('Coverage: '.trim(($trip->coverage_origin ?: '?').' - '.($trip->coverage_destination ?: '?'))),
                ReceiptLine::plain('Terminal: '.($trip->origin ?: ($trip->coverage_origin ?: '—'))),
            );
        }
        array_push($lines,
            ReceiptLine::row('Driver', $trip->driver?->name ?: '—'),
            ReceiptLine::row('Conductor', $trip->conductor?->name ?: '—'),
            // Departure = when the bus left the terminal (Terminal Receipt).
            ReceiptLine::row('Departure Time', $trip->marked_on_trip_at ? $this->clock($trip->marked_on_trip_at) : '—'),
            ReceiptLine::row('Arrival Time', $trip->ended_at ? $this->clock($trip->ended_at) : '—'),

            ReceiptLine::section('REMITTANCE INFO'),
            ReceiptLine::row('Total Passengers', (string) ($counts['Terminal'] + $counts['Pickup'])),
            ReceiptLine::row('Terminal Passenger(s)', (string) $counts['Terminal']),
            ReceiptLine::row('Pick-up Passenger(s)', (string) $counts['Pickup']),
            ReceiptLine::row('Via Terminal', $this->money($r['by_boarding_type']['Terminal'])),
            ReceiptLine::row('Via Pick-up', $this->money($r['by_boarding_type']['Pickup'])),
            ReceiptLine::row('Total Articles', $this->money($r['by_fare_mode']['article'])),
            ReceiptLine::row('Total Dispatch', $this->money($r['total_dispatch'])),

            ReceiptLine::section('BREAKDOWN'),
            ReceiptLine::row('Total Cash', $this->money(max(0.0, $r['by_payment_method']['Cash'] - $r['total_dispatch']))),
            ReceiptLine::row('Total QR Payments', $this->money($r['by_payment_method']['QR'])),
        );
        if ($r['by_payment_method']['E-Wallet'] > 0) {
            $lines[] = ReceiptLine::row('Total E-Wallet', $this->money($r['by_payment_method']['E-Wallet']));
        }
        array_push($lines,
            ReceiptLine::row('Total Tickets', (string) ($r['ticket_count'] + $r['refunded_count'])),
            ReceiptLine::row('Remitted Amount', $r['remitted_amount'] !== null ? $this->money($r['remitted_amount']) : 'Pending'),
        );

        if ($r['stage'] !== 'pending' && $r['counted_total'] !== null) {
            array_push($lines,
                ReceiptLine::section('CASH COUNT'),
                ReceiptLine::row('Cashier', $trip->remittanceReceivedBy?->name ?: '—'),
                ReceiptLine::row('Counted', $this->money($r['counted_total'])),
                ReceiptLine::row('Count variance', $this->signedMoney($r['count_variance'] ?? 0)),
            );
        }

        if ($trip->remittance_approved_at !== null) {
            array_push($lines,
                ReceiptLine::section('APPROVAL'),
                ReceiptLine::row('Approved by', $r['approved_by'] ?: '—'),
                ReceiptLine::row('Approved at', $this->dateTime($trip->remittance_approved_at)),
            );
            if ($trip->remittance_excess_amount > 0) {
                $lines[] = ReceiptLine::row('Excess', $this->money($trip->remittance_excess_amount));
            }
            if ($trip->remittance_short_amount > 0) {
                $lines[] = ReceiptLine::row('Short', $this->money($trip->remittance_short_amount));
            }
        }

        $lines[] = ReceiptLine::highlightRow('TOTAL VALUE', $this->peso($r['collected']));

        return ReceiptDocument::make('remittance', 'TRIP REMITTANCE REPORT', $this->brand($trip->company_id), $this->width($trip->company_id))
            ->reference($trip->reference)
            ->lines($lines);
    }

    /**
     * BITS Conductor's passenger ticket (PassengerTicketReceipt), laid out to
     * the cooperative's sample:
     *
     *   ORG NAME / OCT 12, 2026 10:54PM / ---
     *   Ticket ID · Bus # · Conductor / ---
     *   ORIGIN TO DESTINATION / TYPE • PICK UP • CASH
     *   (Article, Ref #, Qty x fare — only when they apply) / ---
     *   FARE (or TOTAL) / --- / footer
     */
    public function ticket(Ticket $ticket, int $qty = 1): ReceiptDocument
    {
        $qty = max(1, min(50, $qty));
        $ticket->loadMissing(['route', 'passengerType', 'trip.conductor']);

        $fare = (float) $ticket->fare;
        $number = 'TKT-'.str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT);
        $brand = $this->brand($ticket->company_id);

        $lines = [
            ReceiptLine::title($brand['name']),
            ReceiptLine::center($this->stamp($ticket->issued_at)),
            ReceiptLine::hr(),
            ReceiptLine::row('Ticket ID', $number),
            ReceiptLine::row('Bus #', $ticket->trip?->bus_number ?: '-'),
            ReceiptLine::row('Conductor', mb_strtoupper($ticket->trip?->conductor?->name ?: '-')),
            ReceiptLine::hr(),
        ];

        // ORIGIN TO DESTINATION, then TYPE • BOARDING • PAYMENT.
        if ($ticket->route && ($ticket->route->origin || $ticket->route->destination)) {
            $lines[] = ReceiptLine::center(mb_strtoupper(trim($ticket->route->origin.' TO '.$ticket->route->destination)));
        }
        $details = array_filter([
            $ticket->passengerType?->name,
            $ticket->route ? ($ticket->boarding_type === 'Pickup' ? 'Pick up' : 'Terminal') : null,
            $ticket->payment_method,
        ]);
        if ($details !== []) {
            $lines[] = ReceiptLine::center(mb_strtoupper(implode(' • ', $details)));
        }

        if ($ticket->article_label) {
            $lines[] = ReceiptLine::row('Article', $ticket->article_label);
        }
        // Tied to the payment method, not just a reference being present — a
        // Cash ticket never shows a Ref # line.
        if ($ticket->payment_method === 'QR' && $ticket->qr_reference) {
            $lines[] = ReceiptLine::row('Ref #', $ticket->qr_reference);
        }
        if ($qty > 1) {
            $lines[] = ReceiptLine::row('Qty', $qty.' x '.$this->peso($fare));
        }

        array_push($lines,
            ReceiptLine::hr(),
            ReceiptLine::boldRow($qty > 1 ? 'TOTAL' : 'FARE', $this->peso($fare * $qty)),
            ReceiptLine::hr(),
        );
        if (trim($brand['footer']) !== '') {
            $lines[] = ReceiptLine::footer(trim($brand['footer']));
        }

        return ReceiptDocument::make('ticket', 'PASSENGER TICKET', $brand, $this->width($ticket->company_id))
            ->reference($number)
            ->lines($lines);
    }

    /**
     * BITS Conductor's Dispatch Receipt (DispatchReceipt). A dispatch is
     * recorded by LOCATION — it's stored in the legacy barker_name column.
     */
    public function dispatch(Dispatch $dispatch): ReceiptDocument
    {
        $dispatch->loadMissing('trip.conductor');

        $number = 'DSP-'.str_pad((string) $dispatch->id, 6, '0', STR_PAD_LEFT);
        $brand = $this->brand($dispatch->company_id);

        $lines = [ReceiptLine::title('DISPATCH RECEIPT')];
        array_push($lines, ...$this->orgHeader($dispatch->company_id));
        array_push($lines,
            ReceiptLine::center($this->stamp($dispatch->dispatched_at)),
            ReceiptLine::hr(),
            ReceiptLine::row('Dispatch #', $number),
            ReceiptLine::row('Bus #', $dispatch->trip?->bus_number ?: '-'),
            ReceiptLine::row('Conductor', $dispatch->trip?->conductor?->name ?: '-'),
            ReceiptLine::hr(),
            ReceiptLine::row('Location', $dispatch->barker_name ?: '-'),
            ReceiptLine::hr(),
            ReceiptLine::boldRow('AMOUNT PAID', $this->peso($dispatch->amount)),
            ReceiptLine::hr(),
        );
        if (trim($brand['footer']) !== '') {
            $lines[] = ReceiptLine::footer(trim($brand['footer']));
        }

        return ReceiptDocument::make('dispatch', 'DISPATCH RECEIPT', $brand, $this->width($dispatch->company_id))
            ->reference($number)
            ->lines($lines);
    }

    /**
     * BITS `receipt/conductor/shift_summary_receipt.php` — every trip run by
     * ANY of this conductor's Active buses on $date.
     */
    public function shiftSummary(User $conductor, Carbon $date): ReceiptDocument
    {
        // Scopes which trips to pull in — any of the conductor's assigned,
        // Active buses. The DETAILS block below must NOT show this whole
        // list though; only the bus(es)/driver(s)/conductor(s) actually
        // used in today's trips, same as Driver and Conductor already were.
        $busIds = $conductor->buses()->where('status', 'Active')->pluck('buses.id');

        $trips = Trip::query()
            ->whereIn('bus_id', $busIds)
            ->whereDate('started_at', $date)
            ->with(['driver', 'conductor'])
            ->withCount(['tickets as ticket_count' => fn ($q) => $q->whereNull('refunded_at')])
            ->withSum(['tickets as net' => fn ($q) => $q->whereNull('refunded_at')], 'fare')
            ->orderBy('started_at')
            ->get();

        $grossSales = (float) $trips->sum('net');
        $busNumbers = $trips->pluck('bus_number')->filter()->unique()->sort()->values();
        $conductorNames = $trips->map(fn ($t) => $t->conductor?->name)->filter()->unique()->values();
        $driverNames = $trips->map(fn ($t) => $t->driver?->name)->filter()->unique()->values();
        $latest = $trips->last();

        $doc = ReceiptDocument::make('shift-summary', 'SHIFT SALES SUMMARY', $this->brand($conductor->company_id), $this->width($conductor->company_id));

        $doc->section('DETAILS', [
            'Route' => $latest && $latest->coverage_origin
                ? [strtoupper($latest->coverage_origin).' TO '.strtoupper((string) $latest->coverage_destination).' VICE VERSA', 'form' => true]
                : null,
            'Bus #' => [$busNumbers->isNotEmpty() ? $busNumbers->implode(', ') : '—', 'form' => true],
            'Driver' => [$driverNames->isNotEmpty() ? $driverNames->implode(', ') : '—', 'form' => true],
            'Conductor' => [$conductorNames->isNotEmpty() ? $conductorNames->implode(', ') : $conductor->name, 'form' => true],
            'Date' => [$this->dateTime($date), 'form' => true],
        ]);

        // Fixed-width "T.ID / ARR / PAX / TCK / AMOUNT" table — a single
        // pre-spaced string per row in the label slot (value left blank),
        // since the two-column label/value renderer has no native table
        // support. Every ticket row is exactly one passenger in this schema
        // (a qty>1 sale is expanded into that many ticket rows at issue
        // time — see IssueTicket), so PAX and TCK are always equal here.
        $tripRows = [$this->tripTableRow('T.ID', 'ARR', 'PAX', 'TCK', 'AMOUNT') => ''];
        foreach ($trips as $t) {
            $tripRows[$this->tripTableRow(
                (string) $t->reference,
                $this->time($t->ended_at ?: $t->started_at),
                (string) $t->ticket_count,
                (string) $t->ticket_count,
                $this->money((float) $t->net),
            )] = '';
        }
        if ($trips->isEmpty()) {
            $tripRows['No trips'] = '';
        }
        $doc->section('TRIP DETAILS', $tripRows);
        $doc->section(null, [
            'Gross Sales:' => [$this->money($grossSales), 'strong' => true, 'total' => true],
        ]);

        // Discount breakdown — same current-settings approximation BITS uses
        // (discount % read from the passenger type's *current* value).
        $discountRows = DB::table('passenger_types as pt')
            ->leftJoin('tickets as tk', function ($join) use ($busIds, $date): void {
                $join->on('tk.passenger_type_id', '=', 'pt.id')
                    ->whereNull('tk.refunded_at')
                    ->whereIn('tk.trip_id', Trip::query()->whereIn('bus_id', $busIds)->whereDate('started_at', $date)->select('id'));
            })
            ->where('pt.status', 'Active')
            ->where('pt.fare_mode', 'Fare Matrix')
            ->where('pt.company_id', $conductor->company_id)
            ->groupBy('pt.id', 'pt.name', 'pt.discount_percent', 'pt.sort_order')
            ->orderBy('pt.sort_order')
            ->get(['pt.name', 'pt.discount_percent', DB::raw('COALESCE(SUM(tk.fare), 0) as fare_total')]);

        $totalDiscount = 0.0;
        $discountSection = [];
        foreach ($discountRows as $row) {
            $pct = (float) $row->discount_percent;
            $fareTotal = (float) $row->fare_total;
            $amount = $pct >= 100 ? $fareTotal : $fareTotal * $pct / (100 - $pct);
            $totalDiscount += $amount;
            if ($amount > 0) {
                $discountSection[$row->name] = $this->money($amount);
            }
        }
        $discountSection['TOTAL DISCOUNT:'] = [$this->money($totalDiscount), 'strong' => true, 'total' => true];
        $doc->section('DISCOUNT DETAILS', $discountSection);

        $byMethod = $this->byPaymentMethodForBuses($busIds, $date, $conductor->company_id);
        $netSales = $byMethod['Cash'] + $byMethod['QR'] + $byMethod['E-Wallet'];
        $doc->section('PAYMENT BREAKDOWN', [
            'Cash' => $this->money($byMethod['Cash']),
            'QR' => $this->money($byMethod['QR']),
            'E-Wallet' => $this->money($byMethod['E-Wallet']),
            'NET SALES:' => [$this->money($netSales), 'strong' => true, 'total' => true],
        ]);

        return $doc->section(null, [
            'Prepared by' => [$conductor->name ?: '—', 'form' => true],
            'Printed' => [$this->dateTimeSeconds(now()), 'form' => true],
        ]);
    }

    /**
     * A single fixed-width "T.ID / ARR / PAX / TCK / AMOUNT" line for the
     * shift summary's trip table — see shiftSummary(). Sized for the 32-char
     * line width the Android ESC/POS formatter renders at.
     */
    private function tripTableRow(string $id, string $arr, string $pax, string $tck, string $amount): string
    {
        return str_pad(mb_substr($id, 0, 6), 6)
            .str_pad(mb_substr($arr, 0, 6), 6)
            .str_pad(mb_substr($pax, 0, 4), 4, ' ', STR_PAD_LEFT)
            .str_pad(mb_substr($tck, 0, 4), 4, ' ', STR_PAD_LEFT)
            .str_pad(mb_substr($amount, 0, 12), 12, ' ', STR_PAD_LEFT);
    }

    /* ----------------------------- helpers ----------------------------- */

    /**
     * BITS Conductor's ReceiptOrgHeader — the company block under every
     * BITS-layout receipt's title: name, address, then "contact / email"
     * (whichever exist), so the slip stands on its own as a document.
     *
     * @return list<array<string, string>>
     */
    private function orgHeader(?int $companyId): array
    {
        $brand = $this->brand($companyId);
        $lines = [ReceiptLine::center($brand['name'])];

        if ($brand['address']) {
            $lines[] = ReceiptLine::center($brand['address']);
        }
        $contact = implode(' / ', array_filter([$brand['contact'], $brand['email']]));
        if ($contact !== '') {
            $lines[] = ReceiptLine::center($contact);
        }

        return $lines;
    }

    /**
     * @return array{name: string, address: ?string, logo_path: ?string, registration_number: ?string, otc_accreditation_number: ?string, contact: ?string, email: ?string, footer: string}
     */
    private function brand(?int $companyId): array
    {
        /** @var CompanySetting|null $s */
        $s = $companyId ? CompanySetting::query()->where('company_id', $companyId)->first() : null;
        /** @var Company|null $company */
        $company = $companyId ? Company::query()->find($companyId) : null;
        $address = $company
            ? implode(', ', array_filter([$company->address_line, $company->address_barangay, $company->address_city, $company->address_province]))
            : '';

        return [
            'name' => $s?->receipt_org_name ?: ($company?->name ?: 'TransitFlow'),
            'address' => $address !== '' ? $address : null,
            'logo_path' => $s?->logo_path,
            'registration_number' => $s?->registration_number,
            'otc_accreditation_number' => $s?->otc_accreditation_number,
            'contact' => $s?->org_contact_number,
            'email' => $s?->org_email,
            'footer' => $s?->ticket_footer ?: 'Thank you for riding with us.',
        ];
    }

    private function width(?int $companyId): float
    {
        $mm = $companyId
            ? CompanySetting::query()->where('company_id', $companyId)->value('receipt_width_mm')
            : null;

        return (float) ($mm ?: 58);
    }

    /**
     * @return array{Cash: float, QR: float, 'E-Wallet': float}
     */
    private function byPaymentMethod(Trip $trip): array
    {
        $rows = $trip->tickets()
            ->whereNull('refunded_at')
            ->select('payment_method', DB::raw('COALESCE(SUM(fare), 0) as total'))
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        return [
            'Cash' => (float) ($rows['Cash'] ?? 0),
            'QR' => (float) ($rows['QR'] ?? 0),
            'E-Wallet' => (float) ($rows['E-Wallet'] ?? 0),
        ];
    }

    /**
     * @param  Collection<int, int>  $busIds
     * @return array{Cash: float, QR: float, 'E-Wallet': float}
     */
    private function byPaymentMethodForBuses($busIds, Carbon $date, int $companyId): array
    {
        $rows = DB::table('tickets as tk')
            ->join('trips as t', 't.id', '=', 'tk.trip_id')
            ->whereIn('t.bus_id', $busIds)
            ->whereDate('t.started_at', $date)
            ->whereNull('tk.refunded_at')
            ->where('tk.company_id', $companyId)
            ->groupBy('tk.payment_method')
            ->select('tk.payment_method', DB::raw('COALESCE(SUM(tk.fare), 0) as total'))
            ->pluck('total', 'payment_method');

        return [
            'Cash' => (float) ($rows['Cash'] ?? 0),
            'QR' => (float) ($rows['QR'] ?? 0),
            'E-Wallet' => (float) ($rows['E-Wallet'] ?? 0),
        ];
    }

    /**
     * @return array{Terminal: int, Pickup: int}
     */
    private function countsByBoarding(Trip $trip): array
    {
        $rows = $trip->tickets()
            ->whereNull('refunded_at')
            ->whereNull('article_label')
            ->select('boarding_type', DB::raw('COUNT(*) as c'))
            ->groupBy('boarding_type')
            ->pluck('c', 'boarding_type');

        return [
            'Terminal' => (int) ($rows['Terminal'] ?? 0),
            'Pickup' => (int) ($rows['Pickup'] ?? 0),
        ];
    }

    private function money(float|int|string $n): string
    {
        return number_format((float) $n, 2);
    }

    /** "₱1,234.50" — BITS's Formats.currency (printers get "P"; see the ESC/POS formatter). */
    private function peso(float|int|string $n): string
    {
        return '₱'.$this->money($n);
    }

    /** "OCT 12, 2026 10:54PM" — the stamp under a BITS receipt's header. */
    private function stamp(?Carbon $c): string
    {
        return $c ? mb_strtoupper($c->format('M d, Y h:iA')) : '—';
    }

    /** "5:00pm" — BITS's Formats.clockTime (the report's own date stamp is printed at the top). */
    private function clock(Carbon $c): string
    {
        return strtolower($c->format('g:iA'));
    }

    private function signedMoney(float|int $n): string
    {
        $n = (float) $n;

        return ($n > 0 ? '+' : '').number_format($n, 2);
    }

    private function date(?Carbon $c): string
    {
        return $c ? $c->format('m-d-Y') : '—';
    }

    private function time(?Carbon $c): string
    {
        return $c ? $c->format('h:iA') : '—';
    }

    private function dateTime(?Carbon $c): string
    {
        return $c ? $c->format('m-d-Y h:iA') : '—';
    }

    private function dateTimeSeconds(?Carbon $c): string
    {
        return $c ? $c->format('m-d-Y h:i:sA') : '—';
    }
}
