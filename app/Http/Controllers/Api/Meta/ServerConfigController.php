<?php

namespace App\Http\Controllers\Api\Meta;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\MobileAppSetting;
use App\Support\LegalDocuments;
use App\Support\ServerConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Public, unauthenticated meta endpoints the mobile app hits on launch —
 * BITS `api/meta/serverConfig.php` / `api/meta/check_update.php`
 * (docs/MIGRATION_MAP.md §K). The app is not built yet; this keeps the
 * contract stable.
 *
 * A company is identified by its public `code` (`?company=PERJODA`); only
 * an Active company resolves.
 */
class ServerConfigController extends Controller
{
    /**
     * GET /api/meta/server-config?company={code}
     */
    public function serverConfig(Request $request): JsonResponse
    {
        [$company, $settings] = $this->resolve($request);
        $companySettings = CompanySetting::query()->firstOrCreate(
            ['company_id' => $company->id],
            ['receipt_org_name' => $company->name],
        );

        return response()->json([
            'data' => [
                'company' => [
                    'code' => $company->code,
                    'name' => $company->name,
                    'uses_terminals' => (bool) $companySettings->uses_terminals,
                    'color_accent' => $companySettings->color_accent,
                    'color_accent_dark' => $companySettings->color_accent_dark,
                    'logo_url' => $companySettings->logo_url,
                ],
                // Only advertise an explicitly configured URL. Falling back to
                // config('app.url') would hand a mobile client a `localhost`
                // address that resolves to the phone itself — the client keeps
                // whatever base URL it already reached us on when this is null.
                'api_base_url' => $this->publicApiBaseUrl($settings),
                // Bumps whenever the platform-wide default changes — lets a
                // client skip re-validating a URL it already has (§B/§K).
                'config_version' => ServerConfig::version(),
                // Lightweight — just the versions/effective dates a client
                // needs to know whether to re-show the consent gate. Full
                // content comes from GET /api/meta/legal.
                'legal' => LegalDocuments::activeSummary(),
                'app' => [
                    'latest_version' => $settings->latest_version,
                    'latest_version_code' => (int) $settings->latest_version_code,
                    'minimum_version' => $settings->minimum_version,
                    'force_update' => (bool) $settings->force_update,
                    'download_url' => $this->downloadUrl($settings),
                ],
            ],
        ]);
    }

    /**
     * GET /api/meta/check-update?company={code}&version={x}&version_code={n}
     */
    public function checkUpdate(Request $request): JsonResponse
    {
        [$company, $settings] = $this->resolve($request);

        $status = $settings->updateStatusFor(
            $request->query('version'),
            $request->filled('version_code') ? $request->integer('version_code') : null,
        );

        return response()->json([
            'data' => $status + [
                'latest_version' => $settings->latest_version,
                'latest_version_code' => (int) $settings->latest_version_code,
                'download_url' => $this->downloadUrl($settings),
                'release_notes' => $settings->release_notes,
            ],
        ]);
    }

    /**
     * @return array{0: Company, 1: MobileAppSetting}
     */
    private function resolve(Request $request): array
    {
        $code = trim((string) $request->query('company', ''));
        abort_if($code === '', 422, 'A company code is required.');

        $company = Company::query()->where('code', $code)->active()->first();
        abort_if($company === null, 404, 'Unknown or inactive company.');

        $settings = MobileAppSetting::query()->firstOrCreate(['company_id' => $company->id]);

        return [$company, $settings];
    }

    /**
     * The API base URL to advertise to mobile clients, in priority order:
     *  1. This company's own explicit `mobile_app_settings.api_base_url`
     *     override (a company self-hosting or fronting its own domain).
     *  2. The platform-wide default a Super Admin set in System
     *     Configuration (App\Support\ServerConfig) — every company shares
     *     one TransitFlow server, so this is the common case.
     *  3. `config('app.url')`, only when it is a real, externally-routable
     *     host (never `localhost` / `127.0.0.1` / `0.0.0.0`, which would
     *     point a phone at itself).
     */
    private function publicApiBaseUrl(MobileAppSetting $settings): ?string
    {
        if (! empty($settings->api_base_url)) {
            return rtrim($settings->api_base_url, '/');
        }

        if (($global = ServerConfig::current()) !== null) {
            return $global;
        }

        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '0.0.0.0') {
            return null;
        }

        return rtrim((string) config('app.url'), '/').'/api';
    }

    private function downloadUrl(MobileAppSetting $settings): ?string
    {
        if ($settings->download_url) {
            return $settings->download_url;
        }

        return $settings->apk_path
            ? Storage::disk('public')->url($settings->apk_path)
            : null;
    }
}
