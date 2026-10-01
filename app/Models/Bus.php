<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\BusFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
        'driver_id',
        'bus_number',
        'plate_number',
        'capacity',
        'model',
        'vehicle_type',
        'status',
    ];

    /**
     * The bus's regular driver (informational — see the add_driver_id_to_buses_table migration).
     *
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * Conductor accounts assigned to run trips on this bus (BITS `conductor_buses`).
     *
     * @return BelongsToMany<User, $this>
     */
    public function conductors(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * Make `$driverId` this bus's driver (null clears it). A driver regularly
     * drives one bus, so they are first released from any other bus.
     */
    public function assignDriver(?int $driverId): void
    {
        if ($driverId !== null) {
            static::query()
                ->where('driver_id', $driverId)
                ->whereKeyNot($this->getKey())
                ->update(['driver_id' => null]);
        }

        $this->update(['driver_id' => $driverId]);
    }
}
