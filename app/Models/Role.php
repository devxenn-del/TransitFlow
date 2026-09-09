<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Role extends Model
{
    protected $fillable = ['company_id', 'key', 'name', 'description', 'is_platform', 'is_admin', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_platform' => 'boolean',
            'is_admin' => 'boolean',
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
     * @param  Builder<Role>  $query
     */
    public function scopeTemplates(Builder $query): void
    {
        $query->whereNull('company_id');
    }

    /**
     * @param  Builder<Role>  $query
     */
    public function scopeForCompany(Builder $query, int $companyId): void
    {
        $query->where('company_id', $companyId);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')->withPivot('allowed')->withTimestamps();
    }

    /**
     * Permission keys this role grants by default (allowed = 1). Used to
     * seed a new user's `user_permissions`.
     *
     * @return Collection<int, string>
     */
    public function grantedPermissionKeys(): Collection // Illuminate\Support\Collection (pluck off a query)
    {
        return $this->permissions()
            ->wherePivot('allowed', true)
            ->pluck('permission_key');
    }
}
