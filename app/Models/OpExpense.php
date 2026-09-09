<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Denominations;
use Database\Factories\OpExpenseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cash operational expense drawn from a bus's takings — BITS
 * `op_day_expenses` (docs/MIGRATION_MAP.md §4.4).
 */
class OpExpense extends Model
{
    /** @use HasFactory<OpExpenseFactory> */
    use BelongsToCompany, HasFactory;

    protected $table = 'op_day_expenses';

    public const CATEGORIES = ['Fuel', 'Toll', 'Repair', 'Parts', 'Meal', 'Parking', 'Allowance', 'Other'];

    public const STATUSES = ['Active', 'Voided'];

    protected $fillable = [
        'company_id', 'bus_id', 'op_date', 'shift', 'category', 'description', 'amount',
        'q1000', 'q500', 'q200', 'q100', 'q50', 'q20', 'q10', 'q5', 'q1',
        'status', 'recorded_by', 'recorded_by_name', 'recorded_at',
        'voided_by', 'voided_at', 'void_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'op_date' => 'date',
            'amount' => 'integer',
            'recorded_at' => 'datetime',
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
     * @return BelongsTo<Bus, $this>
     */
    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'Active';
    }

    /**
     * @param  Builder<OpExpense>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'Active');
    }
}
