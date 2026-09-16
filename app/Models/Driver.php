<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\DriverFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A login-free driver record, chosen per trip. `employee_id` is generated
 * once, on create, in BITS' format: E-{YYMM}-{(1,000,000 + id) padded to 7}.
 */
class Driver extends Model
{
    /** @use HasFactory<DriverFactory> */
    use BelongsToCompany, HasFactory;

    public const STATUSES = ['Active', 'Inactive'];

    protected $fillable = [
        'company_id',
        'name',
        'license_number',
        'contact_number',
        'status',
        'driver_code',
    ];

    protected static function booted(): void
    {
        static::created(function (Driver $driver): void {
            $fills = [];

            if ($driver->employee_id === null) {
                $fills['employee_id'] = sprintf(
                    'E-%s-%07d',
                    ($driver->created_at ?? now())->format('ym'),
                    1_000_000 + $driver->id,
                );
            }

            if ($driver->driver_code === null) {
                $fills['driver_code'] = sprintf('DR-%04d', $driver->id);
            }

            if ($fills !== []) {
                $driver->forceFill($fills)->saveQuietly();
            }
        });
    }

    /**
     * Driver Code is a login credential, not free text — one consistent
     * comparison rule (trim + uppercase) regardless of how it was typed or
     * stored. Used both when generating a code and when checking one at
     * login (AuthController) or on create/update (StoreDriverRequest).
     */
    public static function normalizeCode(?string $code): ?string
    {
        $code = $code === null ? null : strtoupper(trim($code));

        return $code === '' ? null : $code;
    }

    public function isActive(): bool
    {
        return $this->status === 'Active';
    }

    /**
     * @param  Builder<Driver>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'Active');
    }
}
