<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The company's activity / audit trail (docs/PARITY_CHECKLIST.md §L).
 * Read-only; company-scoped by an explicit `company_id` filter (the model
 * is not `BelongsToCompany`). Gated by `permission:audit.view`.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $logs = AuditLog::query()
            ->where('company_id', $request->user()->company_id)
            ->with('user:id,name')
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', $request->string('action').'%'))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->latest('created_at')
            ->paginate($request->integer('per_page', 30));

        return AuditLogResource::collection($logs);
    }
}
