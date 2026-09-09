<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'company_id', 'role', 'role_id', 'status', 'must_change_password', 'password_changed_at', 'first_name', 'middle_name', 'last_name', 'phone', 'address', 'sex'])]
#[Hidden(['password', 'remember_token', 'void_pin_hash', 'pin_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToCompany, HasApiTokens, HasFactory, Notifiable;

    /**
     * Cache of granted permission keys for the current request.
     *
     * @var Collection<int, string>|null
     */
    private ?Collection $permissionKeyCache = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'void_pin_hash' => 'hashed',
            'void_pin_locked_until' => 'datetime',
            'pin_hash' => 'hashed',
            'locked_at' => 'datetime',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'role' => UserRole::class,
        ];
    }

    protected static function booted(): void
    {
        static::created(function (User $user): void {
            if ($user->employee_id === null) {
                $user->forceFill([
                    'employee_id' => sprintf('E-%s-%07d', ($user->created_at ?? now())->format('ym'), $user->id),
                ])->saveQuietly();
            }
        });
    }

    public function hasVoidPin(): bool
    {
        return $this->void_pin_hash !== null;
    }

    public function voidPinIsLocked(): bool
    {
        return $this->void_pin_locked_until !== null && $this->void_pin_locked_until->isFuture();
    }

    public function hasPin(): bool
    {
        return $this->pin_hash !== null;
    }

    /**
     * @return BelongsTo<ThermalPrinter, $this>
     */
    public function thermalPrinter(): BelongsTo
    {
        return $this->belongsTo(ThermalPrinter::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The fine-grained BITS-style role (nullable). Named `accessRole` so it
     * does not collide with the coarse `role` enum column (the portal
     * discriminator). Use `with('accessRole')` to eager-load.
     *
     * @return BelongsTo<Role, $this>
     */
    public function accessRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Per-user permission grants — the live authorization source, exactly
     * as in BITS. A key is effective only when a row exists with
     * `allowed = 1`.
     *
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permissions')
            ->withPivot('allowed')
            ->withTimestamps();
    }

    /**
     * Buses assigned to this conductor (BITS `conductor_buses`). The
     * conductor picks one of these when starting a trip.
     *
     * @return BelongsToMany<Bus, $this>
     */
    public function buses(): BelongsToMany
    {
        return $this->belongsToMany(Bus::class)->withTimestamps();
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    public function isCompanyAdmin(): bool
    {
        return $this->role === UserRole::CompanyAdmin;
    }

    /**
     * Whether this user is a member of (belongs to) the given company.
     * Always false for a platform account, which belongs to no company.
     */
    public function belongsToCompany(Company|int|null $company): bool
    {
        if ($this->company_id === null || $company === null) {
            return false;
        }

        return $this->company_id === ($company instanceof Company ? $company->id : $company);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * BITS' hasPermission(): true when a `user_permissions` row grants this
     * key. The Super Admin short-circuits to true (mirrors the Gate::before
     * hook, so this method is safe to call directly too).
     */
    public function hasPermissionTo(string $permissionKey): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->grantedPermissionKeys()->contains($permissionKey);
    }

    /**
     * All permission keys currently effective for this user, cached for the
     * request. The Super Admin gets every key (they bypass checks anyway —
     * this keeps the `me` payload and nav honest). Everyone else: their
     * `user_permissions` rows with `allowed = 1`.
     *
     * @return Collection<int, string>
     */
    public function grantedPermissionKeys(): Collection
    {
        if ($this->permissionKeyCache !== null) {
            return $this->permissionKeyCache;
        }

        if ($this->isSuperAdmin()) {
            return $this->permissionKeyCache = Permission::query()->pluck('permission_key');
        }

        $keys = $this->permissions()->wherePivot('allowed', true)->pluck('permission_key');

        // A permission the Super Admin has switched off for this company is
        // not effective, no matter what `user_permissions` says.
        if ($this->company_id !== null && ($company = $this->company) !== null) {
            $disabled = $company->disabledPermissionKeys();

            if ($disabled->isNotEmpty()) {
                $keys = $keys->reject(fn (string $key) => $disabled->contains($key))->values();
            }
        }

        return $this->permissionKeyCache = $keys;
    }

    public function forgetCachedPermissions(): void
    {
        $this->permissionKeyCache = null;
    }
}
