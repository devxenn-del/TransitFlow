<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\RouteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A company transport route (origin → destination). Note: this is the
 * Eloquent model `App\Models\Route`, unrelated to the routing facade
 * `Illuminate\Support\Facades\Route` — route files import the facade, app
 * code imports this.
 */
class Route extends Model
{
    /** @use HasFactory<RouteFactory> */
    use BelongsToCompany, HasFactory;

    public const STATUSES = ['Active', 'Inactive'];

    protected $fillable = [
        'company_id',
        'franchise_id',
        'name',
        'origin',
        'destination',
        'status',
    ];

    /**
     * @return BelongsTo<Franchise, $this>
     */
    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    /**
     * @return HasOne<FareMatrix, $this>
     */
    public function fareMatrix(): HasOne
    {
        return $this->hasOne(FareMatrix::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'Active';
    }

    /**
     * BITS' "usable" rule: the route is Active AND it has an Active fare.
     */
    public function isPriced(): bool
    {
        return $this->isActive()
            && $this->fareMatrix !== null
            && $this->fareMatrix->status === 'Active';
    }

    /**
     * @param  Builder<Route>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'Active');
    }

    /**
     * @param  Builder<Route>  $query
     */
    public function scopePriced(Builder $query): void
    {
        $query->where('status', 'Active')
            ->whereHas('fareMatrix', fn (Builder $q) => $q->where('status', 'Active'));
    }
}
