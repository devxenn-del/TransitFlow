<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only list of roles, for the "assign role" pickers. A Super Admin
 * sees the platform templates; a company user sees their own company's
 * roles (falling back to the shared templates if none exist yet).
 */
class RoleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = Role::query()->with('permissions')->orderBy('sort_order');

        if ($user->isSuperAdmin()) {
            $query->templates();
        } else {
            $companyRoles = (clone $query)->forCompany($user->company_id)->get();
            $roles = $companyRoles->isNotEmpty()
                ? $companyRoles
                : $query->templates()->where('is_platform', false)->get();

            return RoleResource::collection($roles);
        }

        return RoleResource::collection($query->get());
    }
}
