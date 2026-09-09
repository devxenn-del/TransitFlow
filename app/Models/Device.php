<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A registered conductor device — BITS `devices` (docs/MIGRATION_MAP.md §K).
 * Upserted by the mobile app on launch / heartbeat; `last_seen_at` is how
 * the admin console tells a live device from an abandoned one.
 */
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use BelongsToCompany, HasFactory;

    public const PLATFORMS = ['android', 'ios', 'web'];

    /** A device not seen within this many minutes reads as "offline". */
    public const STALE_AFTER_MINUTES = 15;

    protected $fillable = [
        'company_id', 'user_id', 'device_uuid', 'platform',
        'model', 'app_version', 'push_token', 'registered_at', 'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->greaterThan(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<Device>  $query
     */
    public function scopeStale(Builder $query): void
    {
        $query->where(fn ($q) => $q
            ->whereNull('last_seen_at')
            ->orWhere('last_seen_at', '<=', now()->subMinutes(self::STALE_AFTER_MINUTES)));
    }
}
