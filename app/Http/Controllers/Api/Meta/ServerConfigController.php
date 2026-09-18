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
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public, unauthenticated meta endpoints the mobile app hits on launch —
 * BITS `api/meta/serverConfig.php` / `api/meta/check_update.php`
 * (docs/MIGRATION_MAP.md §K). The app is not built yet; this keeps the
 * contract stable.
 *
 * A company is identified by its public `code` (`?company=PERJODA`); only
 * an Active company resolves — this is what proves the caller belongs to a
 * real company before the APK is handed over. The `app` block itself
 * (version, force-update, download URL) is the same for every company: one
 * mobile app, platform-wide (App\Models\MobileAppSetting::current()).
 */
class ServerConfigController extends Controller
{
    /**
     * GET /api/meta/server-config?company={code}
     */
    public function serverConfig(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $settings = MobileAppSetting::current();
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
                'app' => $this->appBlock($settings),
            ],
        ]);
    }

    /**
     * GET /api/meta/mobile-app — the one app's published state, no company
     * code required (there's nothing to scope by; every company gets the
     * same build). Powers the login page's direct download link.
     */
    public function mobileApp(): JsonResponse
    {
        return response()->json([
            'data' => $this->appBlock(MobileAppSetting::current()),
        ]);
    }

    /**
     * GET /api/meta/check-update?company={code}&version={x}&version_code={n}
     */
    public function checkUpdate(Request $request): JsonResponse
    {
        $this->resolveCompany($request);
        $settings = MobileAppSetting::current();

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
     * GET /api/meta/mobile-app/download — the actual APK bytes, under the
     * real uploaded filename (`Content-Disposition`), from a URL that never
     * changes across version updates (the file itself is stored under a
     * fixed name — see `Api\SuperAdmin\MobileAppController::uploadApk`).
     * Public: a phone downloading this has no session with the site at all.
     */
    public function downloadApk(): StreamedResponse
    {
        $settings = MobileAppSetting::current();

        abort_if($settings->apk_path === null, 404, 'No app has been uploaded yet.');
        abort_if(! Storage::disk('public')->exists($settings->apk_path), 404, 'The app file is missing.');

        return Storage::disk('public')->download(
            $settings->apk_path,
            $settings->apk_original_name ?: basename($settings->apk_path),
            ['Content-Type' => 'application/vnd.android.package-archive'],
        );
    }

    /**
     * The company code proves the caller belongs to a real, active company —
     * it never selects which app build they get (there is only one).
     */
    private function resolveCompany(Request $request): Company
    {
        $code = trim((string) $request->query('company', ''));
        abort_if($code === '', 422, 'A company code is required.');

        $company = Company::query()->where('code', $code)->active()->first();
        abort_if($company === null, 404, 'Unknown or inactive company.');

        return $company;
    }

    /**
     * The API base URL to advertise to mobile clients, in priority order:
     *  1. The Super Admin's explicit `mobile_app_settings.api_base_url`
     *     override (moving the app to another host entirely).
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

    /**
     * @return array<string, mixed>
     */
    private function appBlock(MobileAppSetting $settings): array
    {
        return [
            'latest_version' => $settings->latest_version,
            'latest_version_code' => (int) $settings->latest_version_code,
            'minimum_version' => $settings->minimum_version,
            'force_update' => (bool) $settings->force_update,
            'download_url' => $this->downloadUrl($settings),
        ];
    }

    private function downloadUrl(MobileAppSetting $settings): ?string
    {
        if ($settings->download_url) {
            return $settings->download_url;
        }

        return $settings->apk_path
            ? route('meta.mobile-app.download')
            : null;
    }
}
