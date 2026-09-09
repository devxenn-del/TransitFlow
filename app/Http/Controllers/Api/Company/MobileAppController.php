<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\UpdateMobileAppRequest;
use App\Http\Resources\MobileAppResource;
use App\Models\MobileAppSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Per-company mobile-app distribution — BITS `admin/mobileapp.php`
 * (docs/MIGRATION_MAP.md §K). The company is always the caller's own; the
 * public app-facing contract lives at `Api\Meta\ServerConfigController`.
 *
 * Route-gated by `permission:mobileapp.view` (read) / `mobileapp.manage`
 * (write + APK upload).
 */
class MobileAppController extends Controller
{
    public function show(Request $request): MobileAppResource
    {
        return MobileAppResource::make($this->settingsFor($request));
    }

    public function update(UpdateMobileAppRequest $request): MobileAppResource
    {
        $settings = $this->settingsFor($request);

        $settings->fill($request->validated());

        if ($settings->isDirty(['latest_version', 'latest_version_code', 'apk_path', 'download_url'])) {
            $settings->published_at = now();
        }

        $settings->save();

        return MobileAppResource::make($settings->fresh());
    }

    /**
     * POST /api/company/mobile-app/apk  (multipart, field `apk`)
     */
    public function uploadApk(Request $request): MobileAppResource
    {
        $request->validate([
            'apk' => ['required', 'file', 'extensions:apk', 'max:153600'], // 150 MB
        ]);

        $settings = $this->settingsFor($request);
        $companyId = $request->user()->company->id;

        $path = $request->file('apk')->store("company-{$companyId}/app", 'public');

        $this->deleteStored($settings->apk_path);
        $settings->update(['apk_path' => $path, 'published_at' => now()]);

        return MobileAppResource::make($settings->fresh());
    }

    public function deleteApk(Request $request): MobileAppResource
    {
        $settings = $this->settingsFor($request);

        $this->deleteStored($settings->apk_path);
        $settings->update(['apk_path' => null]);

        return MobileAppResource::make($settings->fresh());
    }

    private function settingsFor(Request $request): MobileAppSetting
    {
        $company = $request->user()->company;

        abort_if($company === null, 404);

        return MobileAppSetting::query()->firstOrCreate(['company_id' => $company->id]);
    }

    private function deleteStored(?string $path): void
    {
        if ($path !== null && $path !== '' && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
