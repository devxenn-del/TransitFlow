<?php

namespace App\Support;

use App\Models\SystemSetting;
use App\Models\SystemSettingHistory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * The platform-wide "Server Base URL" a Super Admin manages from System
 * Configuration (docs/PARITY_CHECKLIST.md §K) — the default `api_base_url`
 * TransitFlow's mobile app is told to use (`/api/meta/server-config`) when
 * a company hasn't set its own override (`mobile_app_settings.api_base_url`
 * still wins — see `App\Http\Controllers\Api\Meta\ServerConfigController`).
 *
 * Every change is tested (a real `GET {url}/health` round trip, since the
 * convention is `api_base_url` already includes the `/api` prefix) BEFORE
 * it is applied — a URL that fails the test never overwrites the working
 * configuration, only gets logged as a failed attempt. Every applied change
 * (and every attempt) is appended to `system_setting_history`, which is
 * also how "roll back to a previous server" works: it re-validates that
 * previous value the same way, then re-applies it.
 */
class ServerConfig
{
    public const KEY_API_BASE_URL = 'api_base_url';

    private const CONNECT_TIMEOUT_SECONDS = 5;

    public static function current(): ?string
    {
        return SystemSetting::get(self::KEY_API_BASE_URL);
    }

    /** Number of changes ever recorded for this key — shown to the admin as "Configuration Version". */
    public static function version(): int
    {
        return SystemSettingHistory::query()->where('setting_key', self::KEY_API_BASE_URL)->count();
    }

    public static function lastVerifiedAt(): ?Carbon
    {
        return SystemSettingHistory::query()
            ->where('setting_key', self::KEY_API_BASE_URL)
            ->where('status', 'validated')
            ->latest('created_at')
            ->value('created_at');
    }

    /**
     * Well-formed, and — outside local/testing — HTTPS. Never a loopback
     * host (that would point every mobile client at itself). Throws
     * ValidationException on any violation.
     */
    public static function validateFormat(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw ValidationException::withMessages(['api_base_url' => 'Enter a well-formed URL, e.g. https://api.transitflow.com/api.']);
        }

        if (! in_array($parts['scheme'], ['http', 'https'], true)) {
            throw ValidationException::withMessages(['api_base_url' => 'The URL must use http or https.']);
        }

        if ($parts['scheme'] === 'http' && ! app()->environment(['local', 'testing'])) {
            throw ValidationException::withMessages(['api_base_url' => 'HTTPS is required in production.']);
        }

        $host = strtolower($parts['host']);
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '0.0.0.0' || str_starts_with($host, '127.')) {
            throw ValidationException::withMessages(['api_base_url' => 'That host only resolves to the device itself — enter a real, externally-routable address.']);
        }
    }

    /**
     * A live round trip to `{$url}/health`, verifying it is actually a
     * TransitFlow server (not just any server that happens to respond).
     * Never throws for a network-level failure — that's a normal "not
     * reachable" test result, not a validation error.
     *
     * @return array{ok: bool, message: string, latency_ms: ?int}
     */
    public static function testConnection(string $url): array
    {
        self::validateFormat($url);

        $target = rtrim($url, '/').'/health';
        $started = microtime(true);

        try {
            $response = Http::timeout(self::CONNECT_TIMEOUT_SECONDS)->get($target);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach that server: '.self::friendlyNetworkError($e), 'latency_ms' => null];
        }

        $latencyMs = (int) round((microtime(true) - $started) * 1000);

        if (! $response->ok()) {
            return ['ok' => false, 'message' => "Server responded with HTTP {$response->status()}.", 'latency_ms' => $latencyMs];
        }

        if ($response->json('status') !== 'ok') {
            return ['ok' => false, 'message' => 'That responded, but not with a TransitFlow API health check — check the URL.', 'latency_ms' => $latencyMs];
        }

        return ['ok' => true, 'message' => 'Connected — '.($response->json('app') ?? 'TransitFlow').'.', 'latency_ms' => $latencyMs];
    }

    /**
     * Validate, test, and — only on success — apply the new URL. Throws
     * ValidationException (never applying anything) when the format is bad
     * or the connection test fails; the previous value is always left in
     * place until a new one proves itself.
     */
    public static function update(string $newUrl, User $actor): SystemSetting
    {
        $newUrl = rtrim(trim($newUrl), '/');
        $previous = self::current();

        $test = self::testConnection($newUrl);

        if (! $test['ok']) {
            self::logHistory($previous, $newUrl, 'failed', $test['message'], $actor);

            throw ValidationException::withMessages(['api_base_url' => "Could not verify this server: {$test['message']}"]);
        }

        $setting = SystemSetting::query()->updateOrCreate(
            ['setting_key' => self::KEY_API_BASE_URL],
            [
                'setting_value' => $newUrl,
                'description' => 'Default API base URL advertised to the mobile app when a company has not set its own override.',
                'updated_by' => $actor->id,
                'updated_by_name' => $actor->name,
            ],
        );

        self::logHistory($previous, $newUrl, 'validated', null, $actor);
        Audit::record('system_configuration.api_base_url.updated', $setting, ['previous' => $previous, 'new' => $newUrl], actor: $actor);

        return $setting;
    }

    /**
     * Revert to the value a history entry replaced. Re-tests that value
     * first (a server that was fine when it was replaced may not be fine
     * now) — a failed re-test blocks the rollback rather than restoring a
     * now-broken configuration.
     */
    public static function rollback(SystemSettingHistory $entry, User $actor): SystemSetting
    {
        if ($entry->setting_key !== self::KEY_API_BASE_URL) {
            throw ValidationException::withMessages(['history' => 'That history entry is not a server configuration change.']);
        }

        if (! in_array($entry->status, ['validated', 'rolled_back'], true)) {
            throw ValidationException::withMessages(['history' => 'Only a successfully applied configuration can be rolled back to.']);
        }

        $target = $entry->previous_value;
        $current = self::current();

        if ($target === null) {
            $setting = SystemSetting::query()->updateOrCreate(
                ['setting_key' => self::KEY_API_BASE_URL],
                ['setting_value' => null, 'updated_by' => $actor->id, 'updated_by_name' => $actor->name],
            );
            self::logHistory($current, null, 'rolled_back', "Rollback to history #{$entry->id}", $actor);
            Audit::record('system_configuration.api_base_url.rolled_back', $setting, ['previous' => $current, 'new' => null], actor: $actor);

            return $setting;
        }

        $test = self::testConnection($target);

        if (! $test['ok']) {
            self::logHistory($current, $target, 'failed', 'Rollback target failed re-verification: '.$test['message'], $actor);

            throw ValidationException::withMessages(['history' => "That previous server no longer responds: {$test['message']}"]);
        }

        $setting = SystemSetting::query()->updateOrCreate(
            ['setting_key' => self::KEY_API_BASE_URL],
            ['setting_value' => $target, 'updated_by' => $actor->id, 'updated_by_name' => $actor->name],
        );

        self::logHistory($current, $target, 'rolled_back', "Rollback to history #{$entry->id}", $actor);
        Audit::record('system_configuration.api_base_url.rolled_back', $setting, ['previous' => $current, 'new' => $target], actor: $actor);

        return $setting;
    }

    private static function logHistory(?string $previous, ?string $new, string $status, ?string $reason, User $actor): void
    {
        SystemSettingHistory::query()->create([
            'setting_key' => self::KEY_API_BASE_URL,
            'previous_value' => $previous,
            'new_value' => $new,
            'status' => $status,
            'reason' => $reason,
            'changed_by' => $actor->id,
            'changed_by_name' => $actor->name,
        ]);
    }

    private static function friendlyNetworkError(\Throwable $e): string
    {
        return match (true) {
            str_contains($e->getMessage(), 'cURL error 6') => 'the address could not be resolved (DNS failure).',
            str_contains($e->getMessage(), 'cURL error 7') => 'the connection was refused.',
            str_contains($e->getMessage(), 'cURL error 28') || str_contains($e->getMessage(), 'timed out') => 'the connection timed out.',
            str_contains($e->getMessage(), 'cURL error 60') || str_contains(strtolower($e->getMessage()), 'ssl') => 'the SSL certificate could not be verified.',
            default => 'the connection failed.',
        };
    }
}
