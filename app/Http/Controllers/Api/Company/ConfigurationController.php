<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only runtime configuration for the current company — BITS
 * `admin/configuration.php`, which surfaces `.env`-derived values so an
 * admin can confirm how the deployment is set up (docs/MIGRATION_MAP.md §K).
 *
 * Only non-secret, non-sensitive values are exposed: never a key, password,
 * connection string or token. Gated by `permission:company.settings.view`.
 */
class ConfigurationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $company = $request->user()->company;

        $settings = $company
            ? CompanySetting::query()->firstOrCreate(
                ['company_id' => $company->id],
                ['receipt_org_name' => $company->name],
            )
            : null;

        return response()->json([
            'data' => [
                'platform' => [
                    'app_name' => config('app.name'),
                    'environment' => app()->environment(),
                    'debug' => (bool) config('app.debug'),
                    'url' => config('app.url'),
                    'timezone' => config('app.timezone'),
                    'locale' => config('app.locale'),
                    'currency' => 'PHP (₱)',
                    'laravel_version' => Application::VERSION,
                    'php_version' => PHP_VERSION,
                    'db_connection' => config('database.default'),
                    'db_timezone' => config('database.connections.'.config('database.default').'.timezone'),
                    'queue_connection' => config('queue.default'),
                    'mail_mailer' => config('mail.default'),
                ],
                'company' => $company === null ? null : [
                    'name' => $company->name,
                    'code' => $company->code,
                    'status' => $company->status->value,
                    'can_create_accounts' => (bool) $company->can_create_accounts,
                ],
                'features' => $settings === null ? null : [
                    'void_feature_enabled' => (bool) $settings->void_feature_enabled,
                    'void_pin_required' => (bool) $settings->void_pin_required,
                    'receipt_width_mm' => (float) $settings->receipt_width_mm,
                    'pdf_paper_size' => $settings->pdf_paper_size,
                    'pdf_orientation' => $settings->pdf_orientation,
                    'pdf_margin_mm' => (int) $settings->pdf_margin_mm,
                    'has_logo' => $settings->logo_path !== null && $settings->logo_path !== '',
                    'has_qr_payment_image' => $settings->qr_payment_path !== null && $settings->qr_payment_path !== '',
                ],
            ],
        ]);
    }
}
