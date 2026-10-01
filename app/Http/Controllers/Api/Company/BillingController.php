<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Resources\BillingStatementResource;
use App\Http\Resources\CompanyFeeResource;
use App\Models\BillingStatement;
use App\Support\Billing\FeePricing;
use App\Support\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Billing & Fees — the current company's own fees and billing statements,
 * read-only (`permission:billing.view`). Prices come from FeePricing for
 * THIS company only; special-rate rows, internal notes and other
 * companies' pricing are never exposed here. Statements are company-scoped
 * by CompanyScope, so another company's statement is a 404.
 */
class BillingController extends Controller
{
    public function index(CompanyContext $context, FeePricing $pricing): AnonymousResourceCollection
    {
        return CompanyFeeResource::collection($pricing->forCompany($context->company()));
    }

    public function statements(Request $request): AnonymousResourceCollection
    {
        $statements = BillingStatement::query()
            ->with(['company:id,code', 'carriedTo:id,billing_number'])
            ->withCount('items')
            ->orderByDesc('billing_number')
            ->paginate($request->integer('per_page', 12));

        return BillingStatementResource::collection($statements);
    }

    public function showStatement(BillingStatement $statement): BillingStatementResource
    {
        return BillingStatementResource::make($statement->load(['items', 'company:id,code', 'carriedTo:id,billing_number']));
    }
}
