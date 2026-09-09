<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\FareMatrixFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FareMatrix extends Model
{
    /** @use HasFactory<FareMatrixFactory> */
    use BelongsToCompany, HasFactory;

    protected $table = 'fare_matrix';

    public const STATUSES = ['Active', 'Inactive'];

    protected $fillable = [
        'company_id',
        'route_id',
        'amount',
        'discounted_amount',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'discounted_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Route, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }
}
