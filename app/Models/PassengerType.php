<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\PassengerTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PassengerType extends Model
{
    /** @use HasFactory<PassengerTypeFactory> */
    use BelongsToCompany, HasFactory;

    public const FARE_MODES = ['Fare Matrix', 'Manual Amount'];

    public const STATUSES = ['Active', 'Inactive'];

    protected $fillable = [
        'company_id',
        'name',
        'fare_mode',
        'discount_percent',
        'sort_order',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_percent' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<PassengerTypeArticle, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(PassengerTypeArticle::class)->orderBy('sort_order')->orderBy('label');
    }

    public function isManualAmount(): bool
    {
        return $this->fare_mode === 'Manual Amount';
    }

    public function isActive(): bool
    {
        return $this->status === 'Active';
    }

    /**
     * @param  Builder<PassengerType>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'Active');
    }

    /**
     * @param  Builder<PassengerType>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
