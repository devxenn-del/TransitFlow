<?php

namespace App\Support;

use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Terminal;
use App\Models\Ticket;
use App\Models\Trip;
use Illuminate\Support\Facades\DB;

/**
 * BITS Conductor's emergency Cancel Trip (api/trips/cancelPreview.php +
 * api/trips/cancel.php): passengers pay for the part of the trip they
 * actually rode.
 *
 * The conductor says where the trip stopped; each live ticket's refund is
 * its fare minus the fare-matrix fare from where it boarded to that stop
 * (same pricing as App\Actions\IssueTicket). Passengers whose stop was
 * already reached are owed nothing; passengers still on board are
 * pre-selected; tickets with no route to measure (Manual Amount / article
 * sales) are a full refund, left to the conductor. cancel() recomputes the
 * same amounts itself — the client only says which tickets to refund.
 *
 * A full refund sets refunded_at (the ticket drops out of every total); a
 * partial one lowers `fare` to the part ridden and records refund_amount —
 * see the add_cancel_trip_refunds migration.
 */
class TripCancellation
{
    public function __construct(private readonly Trip $trip) {}

    public static function for(Trip $trip): self
    {
        return new self($trip);
    }

    /**
     * The trip's stops in travel order — the route's stops (reversed for a
     * trip running backward along them), or just the coverage ends when the
     * route has none.
     *
     * @return list<string>
     */
    public function stops(): array
    {
        $franchiseId = $this->trip->resolvedFranchiseId();
        $stops = $franchiseId !== null
            ? RouteStop::query()->where('franchise_id', $franchiseId)->orderBy('sort_order')->pluck('name')->all()
            : [];

        $origin = array_search($this->trip->coverage_origin, $stops, true);
        $destination = array_search($this->trip->coverage_destination, $stops, true);
        if ($origin !== false && $destination !== false && $destination < $origin) {
            $stops = array_reverse($stops);
        }

        if ($stops === []) {
            $stops = array_values(array_unique(array_filter([
                $this->trip->coverage_origin ?: $this->trip->origin,
                $this->trip->coverage_destination,
            ])));
        }

        return $stops;
    }

    /**
     * Every live ticket's refund for the trip ending at $stop (null or not on
     * the route = where the trip started).
     *
     * @return array{stops: list<string>, cancel_stop: string|null, collected: float, tickets: list<array<string, mixed>>}
     */
    public function preview(?string $stop): array
    {
        $stops = $this->stops();
        $cancelStop = $stop !== null && in_array($stop, $stops, true)
            ? $stop
            : ($this->terminalStop($stops) ?? $stops[0] ?? null);
        $position = array_flip($stops);
        $stopAt = $cancelStop !== null ? $position[$cancelStop] : null;

        $tickets = $this->trip->tickets()
            ->whereNull('refunded_at')
            ->whereNull('refund_amount')
            ->with(['route', 'passengerType'])
            ->orderBy('id')
            ->get();

        $rows = $tickets->map(function (Ticket $ticket) use ($position, $stopAt, $cancelStop) {
            $fare = (float) $ticket->fare;
            $origin = $ticket->route?->origin;
            $destination = $ticket->route?->destination;
            $usedFare = 0.0;

            $boardedAt = $origin !== null ? ($position[$origin] ?? null) : null;
            $leavesAt = $destination !== null ? ($position[$destination] ?? null) : null;

            if ($ticket->route === null || $boardedAt === null || $leavesAt === null || $stopAt === null) {
                $status = 'manual';
            } elseif ($leavesAt <= $stopAt) {
                $status = 'arrived';
            } elseif ($boardedAt >= $stopAt) {
                $status = 'full';
            } else {
                $ridden = $this->fareFor($ticket, $origin, $cancelStop);
                if ($ridden === null) {
                    $status = 'fare_missing';
                } else {
                    $status = 'partial';
                    $usedFare = min($fare, $ridden);
                }
            }

            $refund = match ($status) {
                'arrived' => 0.0,
                'partial' => round(max(0, $fare - $usedFare), 2),
                default => $fare,
            };

            return [
                'id' => $ticket->id,
                'fare' => $fare,
                'used_fare' => round($usedFare, 2),
                'refund' => $refund,
                'status' => $status,
                'refundable' => $refund > 0,
                'preselect' => $refund > 0 && $status !== 'manual',
                'origin' => $origin,
                'destination' => $destination,
                'passenger_type_name' => $ticket->passengerType?->name,
                'boarding_type' => $ticket->boarding_type,
            ];
        })->values()->all();

        return [
            'stops' => $stops,
            'cancel_stop' => $cancelStop,
            'collected' => round($this->trip->collectedAmount(), 2),
            'tickets' => $rows,
        ];
    }

    /**
     * Ends the trip as Cancelled at $stop, refunding $refundTicketIds (only
     * those the preview says are refundable). Remits everything kept.
     *
     * @param  list<int>  $refundTicketIds
     * @return array{refunded_count: int, refund_amount: float, unmatched_ticket_count: int}
     */
    public function cancel(string $reason, ?string $stop, array $refundTicketIds): array
    {
        $preview = $this->preview($stop);
        $byId = collect($preview['tickets'])->keyBy('id');
        $wanted = array_values(array_unique(array_map('intval', $refundTicketIds)));

        return DB::transaction(function () use ($preview, $byId, $wanted, $reason): array {
            $refundedCount = 0;
            $refundTotal = 0.0;
            $unmatched = 0;

            foreach ($wanted as $id) {
                $row = $byId->get($id);
                if ($row === null || ! $row['refundable']) {
                    $unmatched++;

                    continue;
                }
                $ticket = $this->trip->tickets()->whereKey($id)->lockForUpdate()->first();
                if ($row['refund'] >= $row['fare']) {
                    $ticket->update(['refunded_at' => now(), 'refund_amount' => $row['fare']]);
                } else {
                    $ticket->update(['fare' => round($row['fare'] - $row['refund'], 2), 'refund_amount' => $row['refund']]);
                }
                $refundedCount++;
                $refundTotal += $row['refund'];
            }

            $this->trip->update([
                'status' => 'Cancelled',
                'cancelled_at' => now(),
                'ended_at' => now(),
                'cancellation_reason' => $reason,
                'cancel_stop' => $preview['cancel_stop'],
                'remitted_amount' => round($this->trip->collectedAmount(), 2),
            ]);

            return [
                'refunded_count' => $refundedCount,
                'refund_amount' => round($refundTotal, 2),
                'unmatched_ticket_count' => $unmatched,
            ];
        });
    }

    /**
     * The route stop the trip's terminal sits at — its Default Origin on the
     * web (Terminals › default_route_origin, else the terminal's name),
     * matched whitespace/case-insensitively — or null when it isn't on the route.
     *
     * @param  list<string>  $stops
     */
    private function terminalStop(array $stops): ?string
    {
        $terminal = Terminal::query()->where('name', $this->trip->origin)->first(['name', 'default_route_origin']);
        if ($terminal === null) {
            return null;
        }
        $normalize = fn (?string $s) => strtoupper((string) preg_replace('/\s+/', '', $s ?? ''));
        $wanted = $normalize($terminal->default_route_origin ?: $terminal->name);
        foreach ($stops as $stop) {
            if ($normalize($stop) === $wanted) {
                return $stop;
            }
        }

        return null;
    }

    /** The fare-matrix fare for this ticket's passenger type from $origin to $destination on its route — null when unpriced. */
    private function fareFor(Ticket $ticket, string $origin, string $destination): ?float
    {
        $route = Route::query()
            ->priced()
            ->where('franchise_id', $ticket->route->franchise_id)
            ->where('origin', $origin)
            ->where('destination', $destination)
            ->with('fareMatrix')
            ->first();
        if ($route === null) {
            return null;
        }

        return FareCalculator::compute(
            (float) $route->fareMatrix->amount,
            $route->fareMatrix->discounted_amount !== null ? (float) $route->fareMatrix->discounted_amount : null,
            (float) ($ticket->passengerType?->discount_percent ?? 0),
        );
    }
}
