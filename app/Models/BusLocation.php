<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\BusLocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single GPS position report from a conductor's device during a live trip
 * — BITS `bus_locations` (docs/MIGRATION_MAP.md §H). Append-only: rows are
 * inserted, never updated.
 */
class BusLocation extends Model
{
    /** @use HasFactory<BusLocationFactory> */
    use BelongsToCompany, HasFactory;

    public const UPDATED_AT = null;

    /** A position older than this many seconds is "stale" on the live board. */
    public const STALE_AFTER_SECONDS = 120;

    protected $fillable = [
        'company_id', 'trip_id', 'bus_id',
        'lat', 'lng', 'speed_kph', 'heading', 'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'speed_kph' => 'decimal:1',
            'heading' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function isStale(): bool
    {
        return $this->recorded_at === null
            || $this->recorded_at->lt(now()->subSeconds(self::STALE_AFTER_SECONDS));
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
}
