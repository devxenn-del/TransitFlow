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
        // terminal — it carries the terminal-phase totals. Before that it is
        // just the plain opening slip.
        $isTerminalReceipt = $trip->marked_on_trip_at !== null;

        $doc = ReceiptDocument::make('departure', $isTerminalReceipt ? 'TERMINAL RECEIPT' : 'DEPARTURE SUCCESSFULLY OPENED', $this->brand($trip->company_id), $this->width($trip->company_id))
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
            ]);

        if ($isTerminalReceipt) {
            $byMethod = $this->byPaymentMethod($trip);
            $passengerCount = $trip->tickets()
                ->whereNull('refunded_at')
                ->whereNull('article_label')
                ->count();
            $totalValue = $byMethod['Cash'] + $byMethod['QR'] + $byMethod['E-Wallet'];

            $doc->section(null, [
                'Total Passenger' => (string) $passengerCount,
                'Total Cash' => $this->money($byMethod['Cash']),
                'Total QR' => $this->money($byMethod['QR']),
                'Total Value' => [$this->money($totalValue), 'strong' => true, 'total' => true],
            ]);
        }

        return $doc->section('OPENING SALE DATE/TIME', [
            'Printed' => $this->dateTime($trip->started_at),
        ]);
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

    public function remittance(Trip $trip): ReceiptDocument
    {
        $trip->loadMissing(['driver', 'conductor', 'remittanceReceivedBy']);
        $r = TripRemittance::for($trip)->toArray();

        $counts = $this->countsByBoarding($trip);

        $doc = ReceiptDocument::make('remittance', 'TRIP REMITTANCE REPORT', $this->brand($trip->company_id), $this->width($trip->company_id))
            ->reference($trip->reference)
            ->section('BUS INFO', [
                'Bus #' => $trip->bus_number ?: '—',
                'Coverage' => trim(($trip->coverage_origin ?: '?').' - '.($trip->coverage_destination ?: '?')),
                'Driver' => $trip->driver?->name ?: '—',
                'Conductor' => $trip->conductor?->name ?: '—',
                'Cashier' => $trip->remittanceReceivedBy?->name ?: '—',
            ])
            ->section('REMITTANCE INFO', [
                'Total articles' => $this->money($r['by_fare_mode']['article']),
                'Total Passengers' => (string) $r['ticket_count'],
                'Terminal Passenger(s)' => (string) $counts['Terminal'],
                'Pick-up Passenger(s)' => (string) $counts['Pickup'],
                'Via Terminal' => $this->money($r['by_boarding_type']['Terminal']),
                'Via Pick-up' => $this->money($r['by_boarding_type']['Pickup']),
                'Total Cash' => $this->money($r['by_payment_method']['Cash']),
                'Total QR Payments' => $this->money($r['by_payment_method']['QR']),
                'Total E-wallet payment' => $this->money($r['by_payment_method']['E-Wallet']),
                'Total Dispatch' => $this->money($r['total_dispatch']),
                'Remitted Amount' => $r['remitted_amount'] !== null ? $this->money($r['remitted_amount']) : 'Pending',
                'Total balance' => $r['balance'] !== null ? $this->money($r['balance']) : '—',
                'Total ticket' => (string) ($r['ticket_count'] + $r['refunded_count']),
            ]);

        if ($r['stage'] !== 'pending' && $r['counted_total'] !== null) {
            $doc->section('CASH COUNT', [
                'Counted' => $this->money($r['counted_total']),
                'Count variance' => $this->signedMoney($r['count_variance'] ?? 0),
            ]);
        }

        if ($trip->remittance_approved_at !== null) {
            $doc->section('APPROVAL', [
                'Approved by' => $r['approved_by'] ?: '—',
                'Approved at' => $this->dateTime($trip->remittance_approved_at),
                'Excess' => $trip->remittance_excess_amount > 0 ? $this->money($trip->remittance_excess_amount) : null,
                'Short' => $trip->remittance_short_amount > 0 ? $this->money($trip->remittance_short_amount) : null,
            ]);
        }

        return $doc->section(null, [
            'Total Value' => [$this->money($r['collected']), 'strong' => true, 'total' => true],
        ])->note($this->brand($trip->company_id)['footer']);
    }

    public function ticket(Ticket $ticket, int $qty = 1): ReceiptDocument
    {
        $qty = max(1, min(50, $qty));
        $ticket->loadMissing(['route', 'passengerType', 'trip.conductor']);

        $boarding = $ticket->boarding_type === 'Pickup' ? 'Via Pick-up' : 'Via Terminal';
        $fare = (float) $ticket->fare;

        $doc = ReceiptDocument::make('ticket', 'PASSENGER TICKET', $this->brand($ticket->company_id), $this->width($ticket->company_id))
            ->reference('TKT-'.str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT))
            ->section(null, [
                'Ticket #' => 'TKT-'.str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT),
                'Bus #' => $ticket->trip?->bus_number ?: '—',
                'Conductor' => $ticket->trip?->conductor?->name ?: '—',
                'Issued' => $this->dateTime($ticket->issued_at),
                '---1' => true,
                'Type' => $ticket->passengerType?->name ?: '—',
                'Article' => $ticket->article_label ?: null,
                'From' => $ticket->route?->origin ?: null,
                'To' => $ticket->route?->destination ?: null,
                'Boarded' => $ticket->route ? $boarding : null,
                'Payment' => $ticket->payment_method,
                'Ref #' => $ticket->payment_method === 'QR' && $ticket->qr_reference ? $ticket->qr_reference : null,
                'Qty' => $qty > 1 ? '× '.$qty : null,
                'Unit Fare' => $qty > 1 ? $this->money($fare) : null,
                ($qty > 1 ? 'TOTAL' : 'FARE') => [$this->money($fare * $qty), 'strong' => true, 'total' => true],
            ]);

        return $doc->note($this->brand($ticket->company_id)['footer']);
    }

    public function dispatch(Dispatch $dispatch): ReceiptDocument
    {
        $dispatch->loadMissing('trip.conductor');

        return ReceiptDocument::make('dispatch', 'BARKER DISPATCH RECEIPT', $this->brand($dispatch->company_id), $this->width($dispatch->company_id))
            ->reference('DSP-'.str_pad((string) $dispatch->id, 6, '0', STR_PAD_LEFT))
            ->section(null, [
                'Dispatch #' => 'DSP-'.str_pad((string) $dispatch->id, 6, '0', STR_PAD_LEFT),
                'Bus #' => $dispatch->trip?->bus_number ?: '—',
                'Conductor' => $dispatch->trip?->conductor?->name ?: '—',
                'Dispatched' => $this->dateTime($dispatch->dispatched_at),
                '---1' => true,
                'Barker' => $dispatch->barker_name,
                'AMOUNT PAID' => [$this->money($dispatch->amount), 'strong' => true, 'total' => true],
            ])
            ->note($this->brand($dispatch->company_id)['footer']);
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
     * @return array{name: string, logo_path: ?string, registration_number: ?string, otc_accreditation_number: ?string, contact: ?string, email: ?string, footer: string}
     */
    private function brand(?int $companyId): array
    {
        /** @var CompanySetting|null $s */
        $s = $companyId ? CompanySetting::query()->where('company_id', $companyId)->first() : null;
        $companyName = $companyId ? Company::query()->whereKey($companyId)->value('name') : null;

        return [
            'name' => $s?->receipt_org_name ?: ($companyName ?: 'TransitFlow'),
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
