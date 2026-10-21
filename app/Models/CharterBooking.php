<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\CharterBookingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A charter / hire booking — the Special-trip workflow:
 *
 *   Pending ──approve──▶ Approved ──conductor starts──▶ InProgress ──End Trip──▶ Completed ──settle──▶ Settled
 *      │                    │                              │ (trip cancelled → back to Approved)
 *      └──reject──▶ Rejected └──cancel──▶ Cancelled (also from Pending)
 *
 * Its money (agreed price, deposit/payments/refunds, extra charges, actual
 * expenses) is kept in charter_payments / charter_charges and summed by
 * App\Support\CharterLedger — never part of a trip's ticket sales or
 * passenger remittance (App\Support\TripRemittance).
 */
class CharterBooking extends Model
{
    /** @use HasFactory<CharterBookingFactory> */
    use BelongsToCompany, HasFactory;

    public const STATUSES = ['Pending', 'Approved', 'Rejected', 'InProgress', 'Completed', 'Settled', 'Cancelled'];

    /** Bookings that hold their bus / driver / conductor for their window — see App\Support\CharterConflicts. */
    public const HOLDING = ['Pending', 'Approved', 'InProgress'];

    /** Still editable (details, crew, times) by the office. */
    public const EDITABLE = ['Pending', 'Approved'];

    protected $fillable = [
        'company_id',
        'reference',
        'client_name',
        'contact_person',
        'contact_number',
        'contact_email',
        'purpose',
        'pickup_point',
        'destination',
        'departure_at',
        'return_at',
        'passenger_count',
        'agreed_price',
        'notes',
        'bus_id',
        'driver_id',
        'conductor_id',
        'status',
        'approved_by',
        'approved_at',
        'rejected_reason',
        'cancelled_reason',
        'trip_id',
        'settled_by',
        'settled_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'departure_at' => 'datetime',
            'return_at' => 'datetime',
            'approved_at' => 'datetime',
            'settled_at' => 'datetime',
            'agreed_price' => 'decimal:2',
            'passenger_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // "CHR-000123" — from the id, so it's assigned right after insert.
        static::created(function (self $booking): void {
            if ($booking->reference === null) {
                $booking->forceFill(['reference' => self::referenceFor($booking->id)])->saveQuietly();
            }
        });
    }

    public static function referenceFor(int $id): string
    {
        return 'CHR-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    /** @param  Builder<self>  $query */
    public function scopeHolding(Builder $query): void
    {
        $query->whereIn('status', self::HOLDING);
    }

    /**
     * Overlaps [$from, $to] — touching end-to-start doesn't count.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOverlapping(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): void
    {
        $query->where('departure_at', '<', $to)->where('return_at', '>', $from);
    }

    /** @return BelongsTo<Bus, $this> */
    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    /** @return BelongsTo<Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /** @return BelongsTo<User, $this> */
    public function conductor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conductor_id');
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function settler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<CharterPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(CharterPayment::class);
    }

    /** @return HasMany<CharterCharge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(CharterCharge::class);
    }
}
