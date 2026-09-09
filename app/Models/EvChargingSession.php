<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\EvChargingSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bus EV charging session — BITS `ev_charging_sessions`
 * (docs/MIGRATION_MAP.md §2.3). One `Charging` session per bus at a time.
 */
class EvChargingSession extends Model
{
    /** @use HasFactory<EvChargingSessionFactory> */
    use BelongsToCompany, HasFactory;

    public const STATUSES = ['Charging', 'Completed'];

    protected $fillable = [
        'company_id', 'bus_id', 'status', 'started_at', 'battery_start_pct',
        'ended_at', 'battery_end_pct', 'location', 'notes',
        'started_by', 'started_by_name', 'ended_by', 'ended_by_name', 'active_bus_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'battery_start_pct' => 'integer',
            'battery_end_pct' => 'integer',
        ];
    }

    public function isCharging(): bool
    {
        return $this->status === 'Charging';
    }

    public function durationMinutes(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        return (int) round($this->started_at->diffInMinutes($this->ended_at ?? now()));
    }

    /**
     * @return BelongsTo<Bus, $this>
     */
    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    /**
     * @param  Builder<EvChargingSession>  $query
     */
    public function scopeCharging(Builder $query): void
    {
        $query->where('status', 'Charging');
    }
}
