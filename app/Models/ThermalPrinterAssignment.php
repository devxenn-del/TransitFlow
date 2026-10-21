<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only entry in a thermal printer's assignment history — see the
 * create_thermal_printer_assignments_table migration and
 * ThermalPrinter::recordHistory().
 */
class ThermalPrinterAssignment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'action',
        'from_company_id',
        'to_company_id',
        'from_bus_id',
        'to_bus_id',
        'from_bus_label',
        'to_bus_label',
        'from_status',
        'to_status',
        'remarks',
        'user_id',
        'user_name',
        'created_at',
    ];

    /**
     * @return BelongsTo<ThermalPrinter, $this>
     */
    public function printer(): BelongsTo
    {
        return $this->belongsTo(ThermalPrinter::class, 'thermal_printer_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function fromCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'from_company_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function toCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'to_company_id')->withTrashed();
    }
}
