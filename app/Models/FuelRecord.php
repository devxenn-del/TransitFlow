<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\FuelRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fuel purchase for a bus — BITS `fuel_records` (docs/MIGRATION_MAP.md §2.3).
 */
class FuelRecord extends Model
{
    /** @use HasFactory<FuelRecordFactory> */
    use BelongsToCompany, HasFactory;

    public const FUEL_TYPES = ['Diesel', 'Gasoline'];

    protected $fillable = [
        'company_id', 'bus_id', 'fuel_type', 'liters', 'price_per_liter', 'amount_paid',
        'odometer', 'station', 'fueled_at', 'notes',
        'recorded_by', 'recorded_by_name', 'recorded_by_role',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'liters' => 'decimal:2',
            'price_per_liter' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'fueled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Bus, $this>
     */
    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }
}
