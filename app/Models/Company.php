<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Enums\PricingPlan;
use App\Enums\UserRole;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A transport company (tenant). NOT itself company-owned — this is the
 * root of the ownership tree — so it deliberately does not use
 * App\Models\Concerns\BelongsToCompany.
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'slug',
        'email',
        'phone',
        'address_line',
        'address_barangay',
        'address_city',
        'address_province',
        'logo_path',
        'status',
        'can_create_accounts',
        'pricing_plan',
        'billing_cycle_day',
        'next_billing_number',
    ];

    /** Billing periods start on this day of the month at the latest, so every month has it. */
    public const MAX_BILLING_CYCLE_DAY = 28;

    protected static function booted(): void
    {
        // A new company's billing cycle starts on the day it registers.
        static::creating(function (Company $company): void {
            $company->billing_cycle_day ??= min(today()->day, self::MAX_BILLING_CYCLE_DAY);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'can_create_accounts' => 'boolean',
            'pricing_plan' => PricingPlan::class,
            'billing_cycle_day' => 'integer',
            'next_billing_number' => 'integer',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function admins(): HasMany
    {
        return $this->hasMany(User::class)->where('role', UserRole::CompanyAdmin->value);
    }

    /**
     * @return HasOne<CompanySetting, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(CompanySetting::class);
    }

    /**
     * @return HasMany<Bus, $this>
     */
    public function buses(): HasMany
    {
        return $this->hasMany(Bus::class);
    }

    /**
     * @return HasMany<Driver, $this>
     */
    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    /**
     * Conductor accounts — the company's users holding its `conductor` role.
     *
     * @return HasMany<User, $this>
     */
    public function conductors(): HasMany
    {
        return $this->hasMany(User::class)->whereRelation('accessRole', 'key', 'conductor');
    }

    /**
     * @return HasMany<CompanyDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(CompanyDocument::class);
    }

    /**
     * @return HasMany<Trip, $this>
     */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Fees explicitly assigned to this company (fees that apply to every
     * company are not listed here — see Fee::scopeAppliedTo()).
     *
     * @return BelongsToMany<Fee, $this>
     */
    public function assignedFees(): BelongsToMany
    {
        return $this->belongsToMany(Fee::class, 'company_fee')->withTimestamps();
    }

    /**
     * Special (company-specific) fee pricing — Super Admin configuration.
     *
     * @return HasMany<CompanyFeeRate, $this>
     */
    public function feeRates(): HasMany
    {
        return $this->hasMany(CompanyFeeRate::class);
    }

    /**
     * @return HasMany<BillingStatement, $this>
     */
    public function billingStatements(): HasMany
    {
        return $this->hasMany(BillingStatement::class);
    }

    /**
     * Per-company permission-availability overrides set by the Super Admin.
     * Only rows that deviate from the default ("available") are stored.
     *
     * @return BelongsToMany<Permission, $this>
     */
    public function permissionOverrides(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'company_permissions')
            ->withPivot('enabled')
            ->withTimestamps();
    }

    /** @var Collection<int, string>|null */
    private ?Collection $disabledPermissionKeyCache = null;

    /**
     * Permission keys the Super Admin has switched OFF for this company.
     * Cached for the request.
     *
     * @return Collection<int, string>
     */
    public function disabledPermissionKeys(): Collection
    {
        return $this->disabledPermissionKeyCache ??= $this->permissionOverrides()
            ->wherePivot('enabled', false)
            ->pluck('permission_key');
    }

    public function forgetDisabledPermissions(): void
    {
        $this->disabledPermissionKeyCache = null;
    }

    /**
     * Whether a Company Admin here may use / assign this permission key —
     * true unless the Super Admin has explicitly disabled it.
     */
    public function permissionIsAvailable(string $permissionKey): bool
    {
        return ! $this->disabledPermissionKeys()->contains($permissionKey);
    }

    /**
     * The billing period that starts in the given month: from the company's
     * cycle day to the day before it a month later (e.g. 07/24 – 08/23).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function billingPeriodStartingIn(CarbonInterface $month): array
    {
        $start = CarbonImmutable::create($month->year, $month->month, $this->billing_cycle_day);

        return [$start, $start->addMonthNoOverflow()->subDay()];
    }

    /**
     * The billing period that contains the given date.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function billingPeriodContaining(CarbonInterface $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        return $this->billingPeriodStartingIn($day->day >= $this->billing_cycle_day ? $day : $day->subMonthNoOverflow());
    }

    public function isActive(): bool
    {
        return $this->status === CompanyStatus::Active;
    }

    /**
     * @param  Builder<Company>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', CompanyStatus::Active->value);
    }
}
