<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received from (or refunded to) a charter client — Deposit, Payment
 * or Refund. See App\Support\CharterLedger.
 */
class CharterPayment extends Model
{
    use BelongsToCompany;

    public const TYPES = ['Deposit', 'Payment', 'Refund'];

    public const METHODS = ['Cash', 'Bank', 'E-Wallet', 'Other'];

    protected $fillable = [
        'company_id',
        'charter_booking_id',
        'type',
        'amount',
        'method',
        'reference_no',
        'paid_at',
        'notes',
        'recorded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CharterBooking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(CharterBooking::class, 'charter_booking_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
