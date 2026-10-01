<?php

namespace App\Models;

use App\Enums\BillingFrequency;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\FeeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A platform fee the Super Admin bills companies for (system, rental,
 * support …). `amount` is the STANDARD rate. Not company-owned — company
 * applicability is `applies_to_all_companies` or the `company_fee` pivot,
 * and company-specific pricing lives in CompanyFeeRate. Price resolution:
 * App\Support\Billing\FeePricing.
 */
class Fee extends Model
{
    /** @use HasFactory<FeeFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'amount',
        'billing_frequency',
        'billing_interval_months',
        'applies_to_all_companies',
        'is_active',
        'effective_date',
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
            'billing_frequency' => BillingFrequency::class,
            'billing_interval_months' => 'integer',
            'applies_to_all_companies' => 'boolean',
            'is_active' => 'boolean',
            'effective_date' => 'date',
        ];
    }

    /**
     * Companies explicitly assigned this fee (only meaningful when it does
     * not apply to every company).
     *
     * @return BelongsToMany<Company, $this>
     */
    public function assignedCompanies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_fee')->withTimestamps();
    }

    /**
     * @return HasMany<CompanyFeeRate, $this>
     */
    public function specialRates(): HasMany
    {
        return $this->hasMany(CompanyFeeRate::class);
    }

    /**
     * Fees billed to the given company: active, and either applied to every
     * company or explicitly assigned to it.
     *
     * @param  Builder<Fee>  $query
     */
    public function scopeAppliedTo(Builder $query, Company $company): void
    {
        $query->where('is_active', true)->where(fn (Builder $q) => $q
            ->where('applies_to_all_companies', true)
            ->orWhereHas('assignedCompanies', fn (Builder $sub) => $sub->whereKey($company->id)));
    }

    public function intervalMonths(): ?int
    {
        return $this->billing_frequency->intervalMonths($this->billing_interval_months);
    }

    public function frequencyLabel(): string
    {
        return $this->billing_frequency->label($this->billing_interval_months);
    }

    /**
     * The first charge date on or after $from. Charges fall on the effective
     * date, then every interval after (only once for a one-time fee). Null
     * when nothing is left to charge.
     */
    public function chargeDateOnOrAfter(CarbonInterface $from): ?CarbonImmutable
    {
        $date = $this->effective_date->toImmutable();
        $day = CarbonImmutable::parse($from)->startOfDay();

        if ($date->gte($day)) {
            return $date;
        }

        $interval = $this->intervalMonths();

        if ($interval === null) {
            return null;
        }

        $periods = intdiv((int) $date->diffInMonths($day), $interval);

        do {
            $next = $date->addMonthsNoOverflow($periods * $interval);
            $periods++;
        } while ($next->lt($day));

        return $next;
    }

    /**
     * The next charge date AFTER $from — a monthly fee effective today was
     * charged today and next charges a month from now. Null when nothing is
     * left to charge (a one-time fee already charged).
     */
    public function nextBillingDate(CarbonInterface $from): ?CarbonImmutable
    {
        return $this->chargeDateOnOrAfter(CarbonImmutable::parse($from)->startOfDay()->addDay());
    }
}
