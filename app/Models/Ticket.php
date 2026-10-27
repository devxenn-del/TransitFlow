<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use BelongsToCompany, HasFactory;

    public const PAYMENT_METHODS = ['Cash', 'E-Wallet', 'QR'];

    public const BOARDING_TYPES = ['Terminal', 'Pickup'];

    protected $fillable = [
        'company_id',
        'trip_id',
        'ticket_group_id',
        'route_id',
        'passenger_type_id',
        'boarding_type',
        'payment_method',
        'qr_reference',
        'article_label',
        'fare',
        'issued_at',
        'refunded_at',
        'refund_amount',
        'client_uuid',
        'offline_sync',
    ];

    /**
     * SUM() of every refund on a set of tickets: a partial refund's
     * refund_amount, or a full refund's whole fare (older full refunds
     * never recorded refund_amount).
     */
    public const REFUNDED_SUM_SQL = 'COALESCE(SUM(CASE WHEN refunded_at IS NOT NULL THEN COALESCE(refund_amount, fare) ELSE COALESCE(refund_amount, 0) END), 0)';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fare' => 'decimal:2',
            'issued_at' => 'datetime',
            'refunded_at' => 'datetime',
            'refund_amount' => 'decimal:2',
            'offline_sync' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * @return BelongsTo<TicketGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TicketGroup::class, 'ticket_group_id');
    }

    /**
     * @return BelongsTo<Route, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /**
     * @return BelongsTo<PassengerType, $this>
     */
    public function passengerType(): BelongsTo
    {
        return $this->belongsTo(PassengerType::class);
    }
}
