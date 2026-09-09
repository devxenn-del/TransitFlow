<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\RouteStopFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named stop on a franchise's route. The `sort_order` across a
 * franchise's stops is what lays out its fare-matrix grid.
 */
class RouteStop extends Model
{
    /** @use HasFactory<RouteStopFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'franchise_id',
        'name',
        'sort_order',
        'status',
    ];

    /**
     * @return BelongsTo<Franchise, $this>
     */
    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }
}
