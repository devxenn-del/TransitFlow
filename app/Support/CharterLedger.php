<?php

namespace App\Support;

use App\Models\CharterBooking;
use Illuminate\Contracts\Support\Arrayable;

/**
 * A charter's money, kept apart from ticket sales and passenger remittance
 * (App\Support\TripRemittance never sees it):
 *
 *   amount_due   = agreed_price + charges        (what the client owes)
 *   amount_paid  = deposits + payments − refunds  (what the client has paid)
 *   balance      = amount_due − amount_paid       (still to collect; < 0 = overpaid)
 *   net_income   = amount_due − expenses          (what the company keeps)
 *
 * @implements Arrayable<string, mixed>
 */
final class CharterLedger implements Arrayable
{
    private function __construct(private readonly CharterBooking $booking) {}

    public static function for(CharterBooking $booking): self
    {
        $booking->loadMissing(['payments', 'charges']);

        return new self($booking);
    }

    public function balance(): float
    {
        return round($this->amountDue() - $this->amountPaid(), 2);
    }

    public function amountDue(): float
    {
        return round((float) $this->booking->agreed_price + $this->charges(), 2);
    }

    public function amountPaid(): float
    {
        return round($this->paid('Deposit') + $this->paid('Payment') - $this->paid('Refund'), 2);
    }

    private function charges(): float
    {
        return (float) $this->booking->charges->where('kind', 'Charge')->sum('amount');
    }

    private function expenses(): float
    {
        return (float) $this->booking->charges->where('kind', 'Expense')->sum('amount');
    }

    private function paid(string $type): float
    {
        return (float) $this->booking->payments->where('type', $type)->sum('amount');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $balance = $this->balance();

        return [
            'agreed_price' => round((float) $this->booking->agreed_price, 2),
            'charges' => round($this->charges(), 2),
            'amount_due' => $this->amountDue(),
            'deposits' => round($this->paid('Deposit'), 2),
            'payments' => round($this->paid('Payment'), 2),
            'refunds' => round($this->paid('Refund'), 2),
            'amount_paid' => $this->amountPaid(),
            'balance' => $balance,
            'expenses' => round($this->expenses(), 2),
            'net_income' => round($this->amountDue() - $this->expenses(), 2),
            // Settle is offered once the trip is done and nothing is owed either way.
            'settleable' => $this->booking->status === 'Completed' && abs($balance) < 0.005,
        ];
    }
}
