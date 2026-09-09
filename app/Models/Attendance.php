<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A conductor's clock-in period. Open while `clock_out_at` is null.
 */
class Attendance extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use BelongsToCompany, HasFactory;

    protected $table = 'conductor_attendance';

    public const SOURCES = ['web', 'app'];

    protected $fillable = [
        'company_id',
        'user_id',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->clock_out_at === null;
    }

    /**
     * @param  Builder<Attendance>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('clock_out_at');
    }

    /**
     * @param  Builder<Attendance>  $query
     */
    public function scopeForUser(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId);
    }
}
