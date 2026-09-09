<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\BusFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's bus. Reference implementation of the company-owned model
 * pattern — see App\Models\Concerns\BelongsToCompany. Fleshed out into the
 * full BITS fleet record in Phase 5.
 */
class Bus extends Model
{
    /** @use HasFactory<BusFactory> */
    use BelongsToCompany, HasFactory;

    protected $table = 'buses';

    public const VEHICLE_TYPES = ['electric', 'diesel', 'gasoline'];

    protected $fillable = [
        'company_id',
        'bus_number',
        'plate_number',
        'capacity',
        'model',
        'vehicle_type',
        'status',
    ];
}
