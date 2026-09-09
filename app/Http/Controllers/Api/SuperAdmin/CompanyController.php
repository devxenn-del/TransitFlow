<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Actions\ProvisionCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreCompanyRequest;
use App\Http\Requests\SuperAdmin\UpdateCompanyRequest;
use App\Http\Requests\SuperAdmin\UpdateCompanyStatusRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Platform-level company management. Every action here is Super-Admin-only,
 * enforced by CompanyPolicy (+ the Gate::before super-admin bypass).
 */
class CompanyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Company::class);

        $companies = Company::query()
            ->withCount('users')
            ->when($request->string('status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->string('q')->isNotEmpty(), fn ($q) => $q->where(
                fn ($sub) => $sub->where('name', 'like', "%{$request->string('q')}%")
                    ->orWhere('code', 'like', "%{$request->string('q')}%")
            ))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return CompanyResource::collection($companies);
    }

    public function store(StoreCompanyRequest $request, ProvisionCompany $provisioner): JsonResponse
    {
        $company = $provisioner->handle(
            $request->safe()->except('admin'),
            $request->safe()->array('admin') ?: null,
        );

        Audit::record('company.provisioned', $company, [
            'with_admin' => $request->filled('admin'),
        ], company: $company);

        return CompanyResource::make($company->load('settings')->loadCount('users'))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(Company $company): CompanyResource
    {
        $this->authorize('view', $company);

        return CompanyResource::make($company->load('settings')->loadCount('users'));
    }

    public function update(UpdateCompanyRequest $request, Company $company): CompanyResource
    {
        $company->update($request->validated());

        return CompanyResource::make($company->fresh()->load('settings')->loadCount('users'));
    }

    public function updateStatus(UpdateCompanyStatusRequest $request, Company $company): CompanyResource
    {
        $from = $company->status->value;
        $company->update(['status' => $request->status()->value]);

        Audit::record('company.status_changed', $company, [
            'from' => $from,
            'to' => $request->status()->value,
        ], company: $company);

        return CompanyResource::make($company->fresh());
    }

    public function destroy(Company $company): JsonResponse
    {
        $this->authorize('delete', $company);

        $company->delete();

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }
}
