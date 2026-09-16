<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\TripFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Trip extends Model
{
    /** @use HasFactory<TripFactory> */
    use BelongsToCompany, HasFactory;

    public const LIVE = ['Departure', 'OnTrip'];

    public const STATUSES = ['Departure', 'OnTrip', 'Arrived', 'Cancelled'];

    public const TRIP_TYPES = ['Regular', 'Special'];

    protected $fillable = [
        'company_id',
        'conductor_id',
        'bus_id',
        'driver_id',
        'bus_number',
        'reference',
        'origin',
        'coverage_origin',
        'coverage_destination',
        'trip_type',
        'at_terminal',
        'op_date',
        'shift',
        'status',
        'started_at',
        'marked_on_trip_at',
        'ended_at',
        'cancelled_at',
        'cancellation_reason',
        'force_ended_by',
        'force_ended_at',
        'force_ended_reason',
        'remitted_amount',
        'remittance_approved_at',
        'remittance_approved_by',
        'remittance_excess_amount',
        'remittance_short_amount',
        'remittance_flagged',
        'remittance_flag_note',
        'remittance_note',
        'remittance_received_at',
        'remittance_received_by',
        'remittance_locked_by',
        'remittance_locked_at',
    ];

    public const REMITTANCE_LOCK_MINUTES = 3;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'op_date' => 'date',
            'started_at' => 'datetime',
            'marked_on_trip_at' => 'datetime',
            'ended_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'force_ended_at' => 'datetime',
            'remittance_approved_at' => 'datetime',
            'remittance_received_at' => 'datetime',
            'remittance_locked_at' => 'datetime',
            'remitted_amount' => 'decimal:2',
            'remittance_excess_amount' => 'decimal:2',
            'remittance_short_amount' => 'decimal:2',
            'remittance_flagged' => 'boolean',
            'at_terminal' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Trip $trip): void {
            if ($trip->reference === null) {
                $trip->reference = self::makeReference($trip->bus_number, $trip->started_at ?? $trip->op_date);
            }
        });
    }

    /**
     * Build a human-readable trip reference: `T-MMDDYY-BUSNUMBER-XXXX-XXXX`.
     * The two 4-char groups are random; the whole string is unique.
     */
    public static function makeReference(?string $busNumber, mixed $when = null): string
    {
        $date = $when === null ? now() : Carbon::parse($when);
        $bus = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $busNumber ?: 'NA')) ?: 'NA';

        do {
            $reference = sprintf(
                'T-%s-%s-%s-%s',
                $date->format('mdy'),
                $bus,
                strtoupper(Str::random(4)),
                strtoupper(Str::random(4)),
            );
        } while (self::withoutGlobalScopes()->where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function conductor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conductor_id');
    }

    /**
     * @return BelongsTo<Bus, $this>
     */
    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * @return HasMany<TicketGroup, $this>
     */
    public function ticketGroups(): HasMany
    {
        return $this->hasMany(TicketGroup::class);
    }

    /**
     * @return HasMany<Dispatch, $this>
     */
    public function dispatches(): HasMany
    {
        return $this->hasMany(Dispatch::class);
    }

    /**
     * The latest denomination count for this trip's remittance (there can
     * be an earlier Voided one after a re-receive).
     *
     * @return HasOne<RemittanceCashCount, $this>
     */
    public function remittanceCashCount(): HasOne
    {
        return $this->hasOne(RemittanceCashCount::class)->latestOfMany();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function forceEndedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'force_ended_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function remittanceApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'remittance_approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function remittanceReceivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'remittance_received_by');
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    public function hasLeftTerminal(): bool
    {
        return $this->status === 'OnTrip';
    }

    /**
     * Total cash+QR collected on this trip (excludes refunded tickets).
     * §4.3: collected = SUM(tickets.fare).
     */
    public function collectedAmount(): float
    {
        return (float) $this->tickets()->whereNull('refunded_at')->sum('fare');
    }

    /**
     * Total barker payouts recorded against this trip (§4.3: total_dispatch).
     */
    public function dispatchTotal(): float
    {
        return (float) $this->dispatches()->sum('amount');
    }

    /**
     * §4.3: suggested_remit = max(0, collected - total_dispatch).
     */
    public function suggestedRemit(): float
    {
        return round(max(0, $this->collectedAmount() - $this->dispatchTotal()), 2);
    }

    public function remittanceIsApproved(): bool
    {
        return $this->remittance_approved_at !== null;
    }

    public function remittanceLockActive(): bool
    {
        return $this->remittance_locked_at !== null
            && $this->remittance_locked_at->greaterThan(now()->subMinutes(self::REMITTANCE_LOCK_MINUTES));
    }

    public function remittanceLockedByOther(int $userId): bool
    {
        return $this->remittanceLockActive() && (int) $this->remittance_locked_by !== $userId;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function remittanceLockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'remittance_locked_by');
    }

    /**
     * @param  Builder<Trip>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', self::LIVE);
    }
}
