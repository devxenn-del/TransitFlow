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

        return ReceiptDocument::make('arrival', 'ARRIVAL SUCCESSFULLY CLOSED', $this->brand($trip->company_id), $this->width($trip->company_id))
            ->reference($trip->reference)
            ->section('TRIP INFO', [
                'Trip #' => $trip->reference,
                'Origin' => $trip->coverage_origin,
                'Destination' => $trip->coverage_destination,
                'Arrival Date' => $this->date($trip->ended_at),
                'Arrival Time' => $this->time($trip->ended_at),
                'Driver' => $trip->driver?->name ?: '—',
                'Conductor' => $trip->conductor?->name ?: '—',
                'Bus Number' => $trip->bus_number ?: '—',
                'Ticket(s)' => (string) $r['ticket_count'],
            ])
            ->section('PASSENGERS', [
                'Total ticket(s)' => (string) $r['ticket_count'],
                'Cancelled' => (string) $r['refunded_count'],
                'Cash Total' => $this->money($r['by_payment_method']['Cash']),
                'Total Value' => [$this->money($r['by_fare_mode']['passenger']), 'strong' => true, 'total' => true],
            ])
            ->section('ARTICLES', [
                'Total Value' => [$this->money($r['by_fare_mode']['article']), 'strong' => true, 'total' => true],
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
        $busIds = $conductor->buses()->where('status', 'Active')->pluck('buses.id');
        $busNumbers = $conductor->buses()->where('status', 'Active')->orderBy('bus_number')->pluck('bus_number');

        $trips = Trip::query()
            ->whereIn('bus_id', $busIds)
            ->whereDate('started_at', $date)
            ->with(['driver', 'conductor'])
            ->withCount(['tickets as ticket_count' => fn ($q) => $q->whereNull('refunded_at')])
            ->withSum(['tickets as net' => fn ($q) => $q->whereNull('refunded_at')], 'fare')
            ->orderBy('started_at')
            ->get();

        $grossSales = (float) $trips->sum('net');
        $conductorNames = $trips->map(fn ($t) => $t->conductor?->name)->filter()->unique()->values();
        $driverNames = $trips->map(fn ($t) => $t->driver?->name)->filter()->unique()->values();
        $latest = $trips->last();

        $doc = ReceiptDocument::make('shift-summary', 'SHIFT SALES SUMMARY', $this->brand($conductor->company_id), $this->width($conductor->company_id));

        $doc->section('DETAILS', [
            'Coverage' => $latest && $latest->coverage_origin
                ? strtoupper($latest->coverage_origin).' TO '.strtoupper((string) $latest->coverage_destination).' VICE VERSA'
                : null,
            'Bus #' => $busNumbers->isNotEmpty() ? $busNumbers->implode(', ') : '—',
            'Driver' => $driverNames->isNotEmpty() ? $driverNames->implode(', ') : '—',
            'Conductor' => $conductorNames->isNotEmpty() ? $conductorNames->implode(', ') : $conductor->name,
            'Date' => $this->dateTime($date),
        ]);

        $tripRows = [];
        foreach ($trips as $i => $t) {
            $tripRows['#'.($i + 1).' '.$this->time($t->started_at)] = (int) $t->ticket_count.' tix, '.$this->money((float) $t->net);
        }
        if ($tripRows === []) {
            $tripRows['—'] = 'No trips';
        }
        $tripRows['Gross Sales'] = [$this->money($grossSales), 'strong' => true, 'total' => true];
        $doc->section('TRIP DEPARTURE DETAILS', $tripRows);

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
        $discountSection['Total Discount'] = [$this->money($totalDiscount), 'strong' => true, 'total' => true];
        $doc->section('DISCOUNT DETAILS', $discountSection);

        $byMethod = $this->byPaymentMethodForBuses($busIds, $date, $conductor->company_id);
        $doc->section(null, [
            'Cash' => $this->money($byMethod['Cash']),
            'QR' => $this->money($byMethod['QR']),
            'Net Sales' => [$this->money($byMethod['Cash'] + $byMethod['QR']), 'strong' => true, 'total' => true],
        ]);

        return $doc->section('DATE AND TIME OF PRINTING', [
            'Prepared by' => $conductor->name ?: '—',
            'Printed' => $this->dateTime(now()),
        ]);
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
     * @return array{Cash: float, QR: float}
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
}
