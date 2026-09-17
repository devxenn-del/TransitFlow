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

    /** Excludes 0/O and 1/I — a driver code is read and typed by hand often enough that those shouldn't be ambiguous. */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

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
                $fills['driver_code'] = self::generateCode($driver->company_id, $driver->created_at ?? now());
            }

            if ($fills !== []) {
                $driver->forceFill($fills)->saveQuietly();
            }
        });
    }

    /**
     * DR-{YYMM}-{4 random}-{4 random} — random rather than sequential
     * (unlike employee_id) since this is a login credential (see
     * AuthController::verifyDriverCode()): a guessable DR-0001/DR-0002
     * series would make every driver's code enumerable. Public — also used
     * by DriverController::update() to backfill a code for a driver that
     * doesn't have one yet, not just on create.
     */
    public static function generateCode(?int $companyId, \Illuminate\Support\Carbon $at): string
    {
        do {
            $code = sprintf('DR-%s-%s-%s', $at->format('ym'), self::randomCodeSegment(), self::randomCodeSegment());
        } while ($companyId !== null && static::withoutGlobalScopes()->where('company_id', $companyId)->where('driver_code', $code)->exists());

        return $code;
    }

    private static function randomCodeSegment(): string
    {
        $segment = '';
        for ($i = 0; $i < 4; $i++) {
            $segment .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        return $segment;
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

    /**
     * The Active driver in `$companyId` whose code matches — used at login
     * (AuthController::verifyDriverCode()), where any conductor holding a
     * valid code for their own company may use it (no fixed pairing).
     */
    public static function findActiveByCode(?int $companyId, ?string $code): ?self
    {
        $normalized = self::normalizeCode($code);
        if ($companyId === null || $normalized === null) {
            return null;
        }

        return static::query()
            ->where('company_id', $companyId)
            ->where('status', 'Active')
            ->where('driver_code', $normalized)
            ->first();
    }

    /**
     * The driver id stamped on `$user`'s current Sanctum token's abilities
     * at sign-in (see AuthController::tokenAbilitiesFor()), or null — no
     * driver verified this session, or a token issued before this feature
     * existed. Shared by AuthController::me() (to report it back to the
     * client) and StartTrip (to require every trip use that same driver).
     */
    public static function verifiedIdForToken(User $user): ?int
    {
        $token = $user->currentAccessToken();
        if ($token === null || ! is_array($token->abilities)) {
            return null;
        }

        foreach ($token->abilities as $ability) {
            if (str_starts_with($ability, 'driver:')) {
                return (int) substr($ability, 7);
            }
        }

        return null;
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
