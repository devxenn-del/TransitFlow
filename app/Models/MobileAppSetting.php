<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Database\Factories\MobileAppSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-company mobile-app distribution + auto-update settings — BITS
 * `admin/mobileapp.php` (docs/MIGRATION_MAP.md §K).
 *
 * Not using BelongsToCompany: it is always loaded by explicit `company_id`
 * (admin endpoints) or resolved from a public `?company={code}` lookup that
 * has no authenticated user to scope by.
 */
class MobileAppSetting extends Model
{
    /** @use HasFactory<MobileAppSettingFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'company_id', 'api_base_url', 'latest_version', 'latest_version_code',
        'minimum_version', 'force_update', 'download_url', 'apk_path',
        'release_notes', 'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latest_version_code' => 'integer',
            'force_update' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Whether a client on `$version` / `$versionCode` should be offered — or
     * forced — to update.
     *
     * @return array{update_available: bool, force_update: bool}
     */
    public function updateStatusFor(?string $version, ?int $versionCode): array
    {
        $latestCode = (int) $this->latest_version_code;
        $clientCode = (int) $versionCode;

        $updateAvailable = $this->latest_version !== null
            && ($latestCode > $clientCode || $this->versionLessThan((string) $version, (string) $this->latest_version));

        $belowMinimum = $this->minimum_version !== null
            && $this->versionLessThan((string) $version, (string) $this->minimum_version);

        return [
            'update_available' => $updateAvailable,
            'force_update' => $updateAvailable && ($this->force_update || $belowMinimum),
        ];
    }

    /**
     * Dotted semantic-ish version compare: "1.4.2" < "1.10.0".
     */
    private function versionLessThan(string $a, string $b): bool
    {
        return version_compare($a === '' ? '0' : $a, $b === '' ? '0' : $b, '<');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
