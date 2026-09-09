<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\FranchiseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An LTFRB franchise. Also the scope of a fare matrix: it owns an ordered
 * list of route stops (its grid axes) and the routes + fares between them.
 */
class Franchise extends Model
{
    /** @use HasFactory<FranchiseFactory> */
    use BelongsToCompany, HasFactory;

    public const STATUSES = ['Active', 'Inactive'];

    protected $fillable = [
        'company_id',
        'applicant_name',
        'route_description',
        'route_origin',
        'route_destination',
        'case_no',
        'status',
    ];

    /**
     * @return HasMany<RouteStop, $this>
     */
    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class)->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @return HasMany<Route, $this>
     */
    public function routes(): HasMany
    {
        return $this->hasMany(Route::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'Active';
    }

    /**
     * @param  Builder<Franchise>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'Active');
    }
}
