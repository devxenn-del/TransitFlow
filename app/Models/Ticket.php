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
        'client_uuid',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fare' => 'decimal:2',
            'issued_at' => 'datetime',
            'refunded_at' => 'datetime',
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
