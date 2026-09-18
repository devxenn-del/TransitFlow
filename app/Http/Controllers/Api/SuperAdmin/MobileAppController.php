<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\UpdateMobileAppRequest;
use App\Http\Resources\MobileAppResource;
use App\Models\MobileAppSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * One mobile app, platform-wide — every company's conductors run the same
 * build, so only the Super Admin publishes its version and hosts the APK
 * (BITS `admin/mobileapp.php`, docs/MIGRATION_MAP.md §K). A company code
 * only identifies the caller for the public contract at
 * `Api\Meta\ServerConfigController` — it never selects a different app.
 * Route-gated by `permission:mobileapp.view` (read) / `mobileapp.manage`
 * (write + APK upload/delete).
 */
class MobileAppController extends Controller
{
    public function show(): MobileAppResource
    {
        return MobileAppResource::make(MobileAppSetting::current());
    }

    public function update(UpdateMobileAppRequest $request): MobileAppResource
    {
        $settings = MobileAppSetting::current();

        $settings->fill($request->validated());

        if ($settings->isDirty(['latest_version', 'latest_version_code', 'apk_path', 'download_url'])) {
            $settings->published_at = now();
        }

        $settings->save();

        return MobileAppResource::make($settings->fresh());
    }

    /**
     * POST /api/super-admin/mobile-app/apk  (multipart, field `apk`)
     *
     * Stored under a fixed name, overwritten on every publish — so the
     * download link/QR code a conductor already has never goes stale across
     * version updates. The real uploaded filename is kept separately and
     * handed back via `Content-Disposition` on download (see
     * `Api\Meta\ServerConfigController::downloadApk`).
     */
    public function uploadApk(Request $request): MobileAppResource
    {
        $request->validate([
            'apk' => ['required', 'file', 'extensions:apk', 'max:153600'], // 150 MB
        ]);

        $settings = MobileAppSetting::current();
        $file = $request->file('apk');

        $path = $file->storeAs('app', 'conductor-app.apk', 'public');

        $settings->update([
            'apk_path' => $path,
            'apk_original_name' => basename($file->getClientOriginalName()),
            'published_at' => now(),
        ]);

        return MobileAppResource::make($settings->fresh());
    }

    public function deleteApk(): MobileAppResource
    {
        $settings = MobileAppSetting::current();

        if ($settings->apk_path !== null && Storage::disk('public')->exists($settings->apk_path)) {
            Storage::disk('public')->delete($settings->apk_path);
        }

        $settings->update(['apk_path' => null, 'apk_original_name' => null]);

        return MobileAppResource::make($settings->fresh());
    }
}
