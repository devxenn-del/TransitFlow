<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A driver's clock-in period — opened and closed together with the shift of
 * the conductor who verified them at sign-in (App\Support\DriverAttendanceSync).
 * Open while `clock_out_at` is null. Mirrors App\Models\Attendance.
 */
class DriverAttendance extends Model
{
    use BelongsToCompany;

    protected $table = 'driver_attendance';

    protected $fillable = [
        'company_id',
        'driver_id',
        'conductor_id',
        'conductor_attendance_id',
        'clock_in_at',
        'clock_out_at',
        'clock_in_source',
        'clock_out_source',
        'closed_by',
        'closed_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /** @return BelongsTo<User, $this> */
    public function conductor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conductor_id');
    }

    /** @return BelongsTo<Attendance, $this> */
    public function conductorAttendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class, 'conductor_attendance_id');
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->clock_out_at === null;
    }

    /** @param  Builder<DriverAttendance>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('clock_out_at');
    }
}
