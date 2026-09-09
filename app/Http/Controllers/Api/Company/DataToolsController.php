<?php

namespace App\Http\Controllers\Api\Company;

use App\Actions\CleanCompanyData;
use App\Actions\ExportCompanyData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CleanCompanyDataRequest;
use App\Models\DataOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Per-company data tools — export (backup) and Clean Data — BITS
 * `admin/backup.php` / `admin/cleandata.php` reworked for multi-tenancy
 * (docs/MIGRATION_MAP.md §K). Everything is scoped to the caller's own
 * company; there is no cross-company or platform-wide operation here.
 */
class DataToolsController extends Controller
{
    /**
     * GET /api/company/data-tools/export — a JSON file of every row this
     * company owns.
     */
    public function export(Request $request, ExportCompanyData $action): StreamedResponse
    {
        $company = $request->user()->company;
        abort_if($company === null, 404);

        $payload = $action->handle($company, $request->user());
        $filename = 'transitflow-'.Str::slug($company->code ?: 'company').'-'.now()->format('Ymd-His').'.json';

        return response()->streamDownload(
            fn () => print (json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            $filename,
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * GET /api/company/data-tools/clean-preview — row counts a clean run
     * would remove.
     */
    public function cleanPreview(Request $request, CleanCompanyData $action): JsonResponse
    {
        $company = $request->user()->company;
        abort_if($company === null, 404);

        $counts = $action->preview($company);

        return response()->json([
            'data' => [
                'counts' => $counts,
                'total' => array_sum($counts),
                'confirm_with' => $company->code,
            ],
        ]);
    }

    /**
     * POST /api/company/data-tools/clean — wipe the transactional tables.
     */
    public function clean(CleanCompanyDataRequest $request, CleanCompanyData $action): JsonResponse
    {
        $summary = $action->handle($request->user()->company, $request->user());

        return response()->json([
            'data' => [
                'deleted' => $summary,
                'total' => array_sum($summary),
            ],
        ]);
    }

    /**
     * GET /api/company/data-tools/history — the export / clean audit feed.
     */
    public function history(Request $request): JsonResponse
    {
        $rows = DataOperation::query()
            ->where('company_id', $request->user()->company_id)
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (DataOperation $op) => [
                'id' => $op->id,
                'type' => $op->type,
                'performed_by_name' => $op->performed_by_name,
                'summary' => $op->summary,
                'total' => is_array($op->summary) ? array_sum($op->summary) : null,
                'created_at' => $op->created_at?->toDateTimeString(),
            ]);

        return response()->json(['data' => $rows]);
    }
}
