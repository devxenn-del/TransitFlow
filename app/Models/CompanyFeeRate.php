<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\CompanyFeeRateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company-specific (special) price for one fee, set by the Super Admin.
 * Never alters the fee's standard amount or any other company's price.
 *
 * Deliberately NOT BelongsToCompany: this is platform pricing configuration,
 * only ever read by Super Admin endpoints (queried through the company) and
 * by App\Support\Billing\FeePricing — company users see the resolved price,
 * never these rows or their internal notes.
 */
class CompanyFeeRate extends Model
{
    /** @use HasFactory<CompanyFeeRateFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id',
        'fee_id',
        'amount',
        'starts_on',
        'ends_on',
        'is_active',
        'internal_notes',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Fee, $this>
     */
    public function fee(): BelongsTo
    {
        return $this->belongsTo(Fee::class)->withTrashed();
    }

    /**
     * Active rates whose date window covers $date.
     *
     * @param  Builder<CompanyFeeRate>  $query
     */
    public function scopeInEffectOn(Builder $query, CarbonInterface $date): void
    {
        $day = $date->toDateString();

        $query->where('is_active', true)
            ->whereDate('starts_on', '<=', $day)
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $day));
    }

    /**
     * `active` (in effect today), `scheduled` (starts later), `expired`, or
     * `inactive` (switched off).
     */
    public function state(?CarbonInterface $on = null): string
    {
        $on ??= today();

        return match (true) {
            ! $this->is_active => 'inactive',
            $this->starts_on->gt($on) => 'scheduled',
            $this->ends_on !== null && $this->ends_on->lt($on->copy()->startOfDay()) => 'expired',
            default => 'active',
        };
    }
}
