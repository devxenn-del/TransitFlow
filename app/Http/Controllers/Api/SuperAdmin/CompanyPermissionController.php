<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Permission;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Super Admin controls which permission keys a company is allowed to use
 * at all. A Company Admin can then only assign the keys left available here.
 *
 * Only company-assignable keys are togglable (the platform-only groups are
 * never a company's to use). The store is a full replace: any key not named
 * in `disabled` is (re)enabled.
 */
class CompanyPermissionController extends Controller
{
    public function show(Request $request, Company $company): JsonResponse
    {
        $this->authorize('update', $company);

        $disabled = $company->disabledPermissionKeys();

        $groups = Permission::query()->companyAssignable()
            ->with('group')
            ->orderBy('permission_group_id')->orderBy('nav_order')->orderBy('name')
            ->get()
            ->groupBy(fn (Permission $p) => $p->group->name)
            ->map(fn ($group) => $group->map(fn (Permission $p) => [
                'key' => $p->permission_key,
                'name' => $p->name,
                'enabled' => ! $disabled->contains($p->permission_key),
            ])->values());

        return response()->json([
            'data' => [
                'groups' => $groups,
                'disabled' => $disabled->values(),
            ],
        ]);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $this->authorize('update', $company);

        $validated = $request->validate([
            'disabled' => ['present', 'array'],
            'disabled.*' => ['string'],
        ]);

        // Restrict to real, company-assignable keys.
        $assignable = Permission::query()->companyAssignable()
            ->pluck('id', 'permission_key');

        $disabledKeys = collect($validated['disabled'])
            ->intersect($assignable->keys())
            ->values();

        DB::transaction(function () use ($company, $assignable, $disabledKeys) {
            // Wipe existing overrides, then write one `enabled = false` row per disabled key.
            $company->permissionOverrides()->detach();

            if ($disabledKeys->isNotEmpty()) {
                $rows = $disabledKeys->mapWithKeys(fn (string $key) => [
                    $assignable[$key] => ['enabled' => false],
                ])->all();

                $company->permissionOverrides()->attach($rows);
            }
        });

        $company->forgetDisabledPermissions();

        Audit::record('company.feature_access.updated', $company, [
            'disabled' => $disabledKeys->values()->all(),
        ], company: $company);

        return $this->show($request, $company);
    }
}
