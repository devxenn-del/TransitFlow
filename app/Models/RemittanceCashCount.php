<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Denominations;
use Database\Factories\RemittanceCashCountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The physical denomination count taken when a trip's remittance is
 * received (docs/MIGRATION_MAP.md §4.3–4.4). One per trip; rolls up into
 * `bus_day_cash_counts`.
 */
class RemittanceCashCount extends Model
{
    /** @use HasFactory<RemittanceCashCountFactory> */
    use BelongsToCompany, HasFactory;

    public const STATUSES = ['Received', 'Voided'];

    protected $fillable = [
        'company_id', 'trip_id', 'bus_id', 'op_date', 'shift',
        'q1000', 'q500', 'q200', 'q100', 'q50', 'q20', 'q10', 'q5', 'q1',
        'counted_total', 'expected_amount', 'variance', 'status',
        'received_by', 'received_by_name', 'received_at',
        'voided_by', 'voided_at', 'void_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'op_date' => 'date',
            'counted_total' => 'integer',
            'expected_amount' => 'integer',
            'variance' => 'integer',
            'received_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, int>
     */
    public function denominations(): array
    {
        return Denominations::normalize($this->attributesToArray());
    }

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * @return BelongsTo<Bus, $this>
     */
    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isReceived(): bool
    {
        return $this->status === 'Received';
    }

    /**
     * @param  Builder<RemittanceCashCount>  $query
     */
    public function scopeReceived(Builder $query): void
    {
        $query->where('status', 'Received');
    }
}
