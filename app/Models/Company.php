<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
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
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'can_create_accounts' => 'boolean',
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
