<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\AdminBusAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Office-admin ⇄ bus, time-boxed & shift-aware — BITS `admin_bus_assignments`
 * (see the migration docblock and docs/MIGRATION_MAP.md §4.4).
 */
class AdminBusAssignment extends Model
{
    /** @use HasFactory<AdminBusAssignmentFactory> */
    use BelongsToCompany, HasFactory;

    public const SHIFTS = ['Morning', 'Evening'];

    public const STATUSES = ['Active', 'Inactive'];

    protected $fillable = [
        'company_id',
        'bus_id',
        'user_id',
        'effective_from',
        'effective_to',
        'shift',
        'start_time',
        'end_time',
        'days_mask',
        'status',
        'assigned_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * @param  Builder<AdminBusAssignment>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'Active');
    }

    /**
     * The Active assignment (if any) for $busId + $shift whose
     * [effective_from, effective_to] window overlaps [$from, $to] — the
     * BITS "same bus, same shift, overlapping dates" conflict rule. A bus
     * may have a different holder per shift on the same dates, so this
     * never compares across shifts.
     *
     * @param  Builder<AdminBusAssignment>  $query
     */
    public function scopeConflicting(
        Builder $query,
        int $busId,
        string $shift,
        string $from,
        ?string $to,
        ?int $ignoreId = null,
    ): Builder {
        $toBound = $to ?? '9999-12-31';

        return $query
            ->where('bus_id', $busId)
            ->where('shift', $shift)
            ->where('status', 'Active')
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where('effective_from', '<=', $toBound)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from));
    }
}
