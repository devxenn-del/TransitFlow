<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\UpdateCompanySettingsRequest;
use App\Http\Resources\CompanySettingResource;
use App\Models\CompanySetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Per-company branding / receipt / organization-identity settings — the
 * multi-tenant split of BITS' single global `system_settings` row
 * (docs/MIGRATION_MAP.md §K). The company is always the caller's own
 * (`$request->user()->company`), never read from the URL or body.
 *
 * Route-gated by `permission:company.settings.view` (read) /
 * `company.settings.manage` (write + image uploads).
 */
class CompanySettingController extends Controller
{
    private const IMAGE_FIELDS = [
        'logo' => 'logo_path',
        'qr-payment' => 'qr_payment_path',
    ];

    public function show(Request $request): JsonResponse
    {
        // Always 200 — a first-time read may lazily create the row, but that
        // is not a "resource created" event to the caller.
        return CompanySettingResource::make($this->settingsFor($request))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_OK);
    }

    public function update(UpdateCompanySettingsRequest $request): CompanySettingResource
    {
        $settings = $this->settingsFor($request);

        $settings->update($request->settingsData());

        return CompanySettingResource::make($settings->fresh());
    }

    /**
     * POST /api/company/settings/{type}  (type ∈ logo | qr-payment)
     */
    public function uploadImage(Request $request, string $type): CompanySettingResource
    {
        $column = $this->columnFor($type);

        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $settings = $this->settingsFor($request);
        $companyId = $request->user()->company->id;

        $path = $request->file('image')->store("company-{$companyId}/branding", 'public');

        $this->deleteStored($settings->{$column});
        $settings->update([$column => $path]);

        return CompanySettingResource::make($settings->fresh());
    }

    /**
     * DELETE /api/company/settings/{type}
     */
    public function deleteImage(Request $request, string $type): CompanySettingResource
    {
        $column = $this->columnFor($type);
        $settings = $this->settingsFor($request);

        $this->deleteStored($settings->{$column});
        $settings->update([$column => null]);

        return CompanySettingResource::make($settings->fresh());
    }

    private function columnFor(string $type): string
    {
        abort_unless(array_key_exists($type, self::IMAGE_FIELDS), 404);

        return self::IMAGE_FIELDS[$type];
    }

    private function settingsFor(Request $request): CompanySetting
    {
        $company = $request->user()->company;

        abort_if($company === null, 404);

        $settings = CompanySetting::query()->firstOrCreate(
            ['company_id' => $company->id],
            ['receipt_org_name' => $company->name],
        );

        // A freshly-created row carries only what we passed; pull the column
        // defaults (accent colours, receipt width, …) back from the DB.
        if ($settings->wasRecentlyCreated) {
            $settings->refresh();
        }

        return $settings;
    }

    private function deleteStored(?string $path): void
    {
        if ($path !== null && $path !== '' && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
