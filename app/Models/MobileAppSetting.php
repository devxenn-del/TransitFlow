<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Database\Factories\MobileAppSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-wide mobile-app distribution + auto-update settings — BITS
 * `admin/mobileapp.php` (docs/MIGRATION_MAP.md §K). One app, one build:
 * every company's conductors run the same APK, so this is a Super-Admin-only
 * singleton, not per company — see `current()`.
 */
class MobileAppSetting extends Model
{
    /** @use HasFactory<MobileAppSettingFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'api_base_url', 'latest_version', 'latest_version_code',
        'minimum_version', 'force_update', 'download_url', 'apk_path',
        'apk_original_name', 'release_notes', 'published_at',
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
     * The one settings row, created on first use.
     */
    public static function current(): self
    {
        return static::query()->orderBy('id')->first() ?? static::query()->create([]);
    }
}
