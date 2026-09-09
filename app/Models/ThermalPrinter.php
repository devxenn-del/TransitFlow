<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A company's thermal printer inventory — BITS `thermal_printers`. Assigned
 * to at most one conductor at a time via `users.thermal_printer_id`
 * (the FK lives on `users`, not here — see holder()).
 */
class ThermalPrinter extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['Active', 'Inactive'];

    protected $fillable = [
        'company_id',
        'device_id',
        'mac_address',
        'model',
        'status',
    ];

    /**
     * @return HasOne<User, $this>
     */
    public function holder(): HasOne
    {
        return $this->hasOne(User::class, 'thermal_printer_id');
    }
}
