<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\UpdateCompanyProfileRequest;
use App\Http\Resources\CompanyResource;
use Illuminate\Http\Request;

/**
 * The authenticated company's own profile. The company is always the
 * caller's own (`$request->user()->company`) — it is never read from the
 * URL or body — so one company can't reach another's profile here no
 * matter what ids it sends.
 */
class CompanyProfileController extends Controller
{
    public function show(Request $request): CompanyResource
    {
        $company = $request->user()->company;

        abort_if($company === null, 404);

        $this->authorize('view', $company);

        return CompanyResource::make($company->load('settings')->loadCount('users'));
    }

    public function update(UpdateCompanyProfileRequest $request): CompanyResource
    {
        $company = $request->user()->company;

        $company->update($request->validated());

        return CompanyResource::make($company->fresh()->load('settings')->loadCount('users'));
    }
}
