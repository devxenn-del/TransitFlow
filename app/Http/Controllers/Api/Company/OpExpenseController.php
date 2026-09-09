<?php

namespace App\Http\Controllers\Api\Company;

use App\Actions\RecordExpense;
use App\Actions\VoidExpense;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreOpExpenseRequest;
use App\Http\Requests\Company\VoidOpExpenseRequest;
use App\Http\Resources\OpExpenseResource;
use App\Models\OpExpense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Operational expenses for the current company — BITS `admin/expenses.php`
 * / `office/expenses.php` (docs/MIGRATION_MAP.md §4.4). Auto-scoped by
 * CompanyScope; capability gated by `permission:expenses.*`.
 */
class OpExpenseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $expenses = OpExpense::query()
            ->with(['bus', 'voidedBy'])
            ->when($request->filled('bus_id'), fn ($q) => $q->where('bus_id', $request->integer('bus_id')))
            ->when($request->filled('shift'), fn ($q) => $q->where('shift', $request->string('shift')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('op_date', $request->date('date')))
            ->orderByDesc('op_date')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return OpExpenseResource::collection($expenses);
    }

    public function store(StoreOpExpenseRequest $request, RecordExpense $action): JsonResponse
    {
        $expense = $action->handle($request->user(), $request->validated());

        return OpExpenseResource::make($expense->load('bus'))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(OpExpense $expense): OpExpenseResource
    {
        return OpExpenseResource::make($expense->load(['bus', 'voidedBy']));
    }

    public function void(VoidOpExpenseRequest $request, OpExpense $expense, VoidExpense $action): OpExpenseResource
    {
        $expense = $action->handle(
            $request->user(),
            $expense,
            $request->string('reason')->value(),
            $request->input('pin'),
        );

        return OpExpenseResource::make($expense->load(['bus', 'voidedBy']));
    }
}
