<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Support\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only list of roles, for the "assign role" pickers. A Super Admin on
 * the platform sees the platform templates; inside a company (a company
 * user, or a Super Admin scoped into one) it's that company's roles
 * (falling back to the shared templates if none exist yet).
 */
class RoleController extends Controller
{
    public function index(Request $request, CompanyContext $context): AnonymousResourceCollection
    {
        $query = Role::query()->with('permissions')->orderBy('sort_order');

        if (! $context->hasCompany()) {
            return RoleResource::collection($query->templates()->get());
        }

        $companyRoles = (clone $query)->forCompany($context->companyId())->get();
        $roles = $companyRoles->isNotEmpty()
            ? $companyRoles
            : $query->templates()->where('is_platform', false)->get();

        return RoleResource::collection($roles);
    }
}
