<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    /** Permission groups that only make sense for the Super Admin. */
    public const PLATFORM_GROUPS = ['Companies', 'Platform Users', 'System Configuration', 'Legal Documents'];

    protected $fillable = [
        'permission_group_id',
        'permission_key',
        'name',
        'description',
        'nav_label',
        'nav_url',
        'nav_icon',
        'nav_order',
    ];

    /**
     * @return BelongsTo<PermissionGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(PermissionGroup::class, 'permission_group_id');
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions')->withPivot('allowed')->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_permissions')->withPivot('allowed')->withTimestamps();
    }

    public function isNavItem(): bool
    {
        return $this->nav_label !== null && $this->nav_url !== null;
    }

    /**
     * Keys a company may grant to its own roles (everything except the
     * platform-only groups).
     *
     * @param  Builder<Permission>  $query
     */
    public function scopeCompanyAssignable(Builder $query): void
    {
        $query->whereHas('group', fn ($q) => $q->whereNotIn('name', self::PLATFORM_GROUPS));
    }
}
