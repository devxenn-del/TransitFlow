<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\DispatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dispatch extends Model
{
    /** @use HasFactory<DispatchFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'trip_id',
        'barker_name',
        'amount',
        'dispatched_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'dispatched_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
