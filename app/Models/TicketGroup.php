<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group ticket — one printed ticket / one payment for a party boarding
 * together (BITS `ticket_groups`, docs/MIGRATION_MAP.md §4.1).
 */
class TicketGroup extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'trip_id', 'boarding_type', 'payment_method', 'qr_reference',
        'line_count', 'passenger_count', 'total_fare', 'issued_by', 'issued_at', 'client_uuid',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_fare' => 'decimal:2',
            'issued_at' => 'datetime',
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
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
