<?php

namespace App\Http\Controllers\Api\Company;

use App\Actions\AdjustCashCountDenominations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\AdjustCashCountRequest;
use App\Http\Resources\CashCountResource;
use App\Http\Resources\OpExpenseResource;
use App\Http\Resources\RemittanceCashCountResource;
use App\Models\CashCount;
use App\Models\CashCountHistory;
use App\Models\OpExpense;
use App\Models\RemittanceCashCount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The per bus / day / shift cash rollup — BITS `admin/cashcount.php` /
 * `office/cashcount.php` (docs/MIGRATION_MAP.md §4.4). Read-only: the rows
 * are maintained by `App\Actions\RollUpBusDayCashCount` from received
 * remittance counts, never entered by hand.
 */
class CashCountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $counts = CashCount::query()
            ->with('bus')
            ->when($request->filled('bus_id'), fn ($q) => $q->where('bus_id', $request->integer('bus_id')))
            ->when($request->filled('shift'), fn ($q) => $q->where('shift', $request->string('shift')))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('op_date', $request->date('date')))
            ->when($request->boolean('variance_only'), fn ($q) => $q->where('variance', '!=', 0))
            ->orderByDesc('op_date')
            ->orderBy('bus_id')
            ->paginate($request->integer('per_page', 20));

        return CashCountResource::collection($counts);
    }

    public function show(Request $request, CashCount $cashCount): JsonResponse
    {
        $cashCount->load(['bus', 'adjustedBy']);

        $contributions = RemittanceCashCount::query()
            ->where('bus_id', $cashCount->bus_id)
            ->whereDate('op_date', $cashCount->op_date)
            ->where('shift', $cashCount->shift)
            ->with('voidedBy')
            ->orderByDesc('id')
            ->get();

        $expenses = OpExpense::query()
            ->where('bus_id', $cashCount->bus_id)
            ->whereDate('op_date', $cashCount->op_date)
            ->where('shift', $cashCount->shift)
            ->with('voidedBy')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => CashCountResource::make($cashCount),
            'contributions' => RemittanceCashCountResource::collection($contributions),
            'expenses' => OpExpenseResource::collection($expenses),
            'history' => CashCountHistory::query()
                ->where('bus_id', $cashCount->bus_id)
                ->whereDate('op_date', $cashCount->op_date)
                ->where('shift', $cashCount->shift)
                ->orderBy('id')
                ->get(['id', 'type', 'ref_code', 'description', 'amount', 'recorded_by_name', 'authorized_by_name', 'created_at']),
        ]);
    }

    public function adjust(AdjustCashCountRequest $request, CashCount $cashCount, AdjustCashCountDenominations $action): CashCountResource
    {
        $rollup = $action->handle(
            $request->user(),
            $cashCount,
            $request->only(['q1000', 'q500', 'q200', 'q100', 'q50', 'q20', 'q10', 'q5', 'q1']),
            $request->string('reason')->value(),
            $request->input('pin'),
        );

        return CashCountResource::make($rollup->load(['bus', 'adjustedBy']));
    }
}
