<?php

namespace App\Support;

use App\Models\Trip;
use Illuminate\Support\Facades\DB;

/**
 * The BITS remittance breakdown for a single trip — docs/MIGRATION_MAP.md §4.3.
 *
 *   collected       = SUM(tickets.fare)              # refunded tickets excluded
 *   total_dispatch  = SUM(dispatches.amount)
 *   suggested_remit = max(0, collected - total_dispatch)
 *   balance         = collected - remitted_amount
 *   variance        = remitted_amount - suggested_remit   # + excess / - short
 *
 * Also splits collected by payment method, boarding type and fare mode
 * (passenger fare vs. Manual-Amount article sale).
 */
class TripRemittance
{
    public function __construct(private readonly Trip $trip) {}

    public static function for(Trip $trip): self
    {
        return new self($trip);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $trip = $this->trip;

        $live = $trip->tickets()->whereNull('refunded_at');

        $collected = (float) (clone $live)->sum('fare');
        $refunded = (float) $trip->tickets()->whereNotNull('refunded_at')->sum('fare');

        $byPayment = (clone $live)
            ->select('payment_method', DB::raw('SUM(fare) as total'))
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        $byBoarding = (clone $live)
            ->select('boarding_type', DB::raw('SUM(fare) as total'))
            ->groupBy('boarding_type')
            ->pluck('total', 'boarding_type');

        $articleTotal = (float) (clone $live)->whereNotNull('article_label')->sum('fare');

        $totalDispatch = $trip->dispatchTotal();
        $suggested = round(max(0, $collected - $totalDispatch), 2);

        $remitted = $trip->remitted_amount !== null ? (float) $trip->remitted_amount : null;
        $variance = $remitted !== null ? round($remitted - $suggested, 2) : null;

        $rcc = $trip->relationLoaded('remittanceCashCount')
            ? $trip->remittanceCashCount
            : $trip->remittanceCashCount()->where('status', 'Received')->first();

        $stage = $trip->remittance_approved_at !== null
            ? 'approved'
            : ($trip->remittance_received_at !== null ? 'received' : 'pending');

        return [
            'trip_id' => $trip->id,
            'reference' => $trip->reference,
            'status' => $trip->status,
            'stage' => $stage,

            'received_at' => $trip->remittance_received_at,
            'counted_total' => $rcc && $rcc->status === 'Received' ? (int) $rcc->counted_total : null,
            'count_variance' => $rcc && $rcc->status === 'Received' ? (int) $rcc->variance : null,
            'ticket_count' => (clone $live)->count(),
            'refunded_count' => $trip->tickets()->whereNotNull('refunded_at')->count(),

            'collected' => round($collected, 2),
            'refunded' => round($refunded, 2),

            'by_payment_method' => [
                'Cash' => round((float) ($byPayment['Cash'] ?? 0), 2),
                'E-Wallet' => round((float) ($byPayment['E-Wallet'] ?? 0), 2),
                'QR' => round((float) ($byPayment['QR'] ?? 0), 2),
            ],
            'by_boarding_type' => [
                'Terminal' => round((float) ($byBoarding['Terminal'] ?? 0), 2),
                'Pickup' => round((float) ($byBoarding['Pickup'] ?? 0), 2),
            ],
            'by_fare_mode' => [
                'passenger' => round($collected - $articleTotal, 2),
                'article' => round($articleTotal, 2),
            ],

            'dispatch_count' => $trip->dispatches()->count(),
            'total_dispatch' => round($totalDispatch, 2),
            'suggested_remit' => $suggested,

            'remitted_amount' => $remitted,
            'balance' => $remitted !== null ? round($collected - $remitted, 2) : null,
            'variance' => $variance,
            'excess' => $variance !== null ? max(0, $variance) : null,
            'short' => $variance !== null ? max(0, -$variance) : null,

            'approved_at' => $trip->remittance_approved_at,
            'approved_by' => $trip->relationLoaded('remittanceApprovedBy')
                ? $trip->remittanceApprovedBy?->name
                : $trip->remittanceApprovedBy()->value('name'),
            'flagged' => (bool) $trip->remittance_flagged,
            'flag_note' => $trip->remittance_flag_note,
        ];
    }
}
