<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Denominations;
use Database\Factories\CashCountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The per bus / operating-date / shift cash rollup — BITS
 * `bus_day_cash_counts` (docs/MIGRATION_MAP.md §4.4).
 *
 * This row is NOT entered by hand. It is created and kept up to date by
 * `App\Actions\RollUpBusDayCashCount` from the received remittance counts
 * (`remittance_cash_counts`) for the same key.
 */
class CashCount extends Model
{
    /** @use HasFactory<CashCountFactory> */
    use BelongsToCompany, HasFactory;

    protected $table = 'bus_day_cash_counts';

    public const SHIFTS = ['Morning', 'Evening'];

    protected $fillable = [
        'company_id', 'bus_id', 'op_date', 'shift',
        'remitted_total', 'counted_total', 'expenses_total', 'net_cash',
        'trip_count', 'expense_count', 'variance',
        'q1000', 'q500', 'q200', 'q100', 'q50', 'q20', 'q10', 'q5', 'q1',
        'adjusted_at', 'adjusted_by', 'adjustment_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'op_date' => 'date',
            'remitted_total' => 'integer',
            'counted_total' => 'integer',
            'expenses_total' => 'integer',
            'net_cash' => 'integer',
            'trip_count' => 'integer',
            'expense_count' => 'integer',
            'variance' => 'integer',
            'adjusted_at' => 'datetime',
        ];
    }

    public static function shiftForHour(int $hour): string
    {
        return $hour >= 17 ? 'Evening' : 'Morning';
    }

    /**
     * @return array<string, int>
     */
    public function denominations(): array
    {
        return Denominations::normalize($this->attributesToArray());
    }

    public function isAdjusted(): bool
    {
        return $this->adjusted_at !== null;
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
    public function adjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }
}
