<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\UpdateFeeAssignmentsRequest;
use App\Http\Resources\FeeResource;
use App\Models\Company;
use App\Models\Fee;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Which companies a fee's standard configuration is assigned to: every
 * company (including future ones), or a chosen set. Super Admin only.
 * Each company gaining or losing the fee gets its own audit row.
 */
class FeeAssignmentController extends Controller
{
    public function update(UpdateFeeAssignmentsRequest $request, Fee $fee): FeeResource
    {
        $appliesToAll = $request->boolean('applies_to_all_companies');
        $wasAppliedToAll = $fee->applies_to_all_companies;

        $changes = DB::transaction(function () use ($request, $fee, $appliesToAll): array {
            $fee->update(['applies_to_all_companies' => $appliesToAll, 'updated_by' => $request->user()->id]);

            // The explicit list is kept even while the fee applies to every
            // company, so switching back restores the previous selection.
            return $appliesToAll
                ? ['attached' => [], 'detached' => []]
                : $fee->assignedCompanies()->sync($request->validated('company_ids', []));
        });

        if ($wasAppliedToAll !== $appliesToAll) {
            Audit::record('billing.fee.assignments_changed', $fee, [
                'changes' => ['applies_to_all_companies' => ['from' => $wasAppliedToAll, 'to' => $appliesToAll]],
            ]);
        }

        $companies = Company::query()->whereKey([...$changes['attached'], ...$changes['detached']])->get()->keyBy('id');

        foreach (['attached' => 'billing.fee.assigned', 'detached' => 'billing.fee.unassigned'] as $key => $action) {
            foreach ($changes[$key] as $companyId) {
                Audit::record($action, $fee, [
                    'fee' => $fee->name,
                    'standard_amount' => (string) $fee->amount,
                ], company: $companies->get($companyId));
            }
        }

        return FeeResource::make($fee->loadCount(['assignedCompanies', 'specialRates']));
    }
}
