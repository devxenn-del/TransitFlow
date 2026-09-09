<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in user's permission-gated navigation — every RbacSeeder
 * catalogue entry carrying nav metadata that their effective permissions
 * unlock. Drives the mobile app's dynamic, role-based menu.
 *
 * Visibility here is a convenience only: every linked page still enforces
 * its own `permission:` middleware server-side, so a stale or tampered
 * client-side list can never grant access beyond what the backend allows.
 */
class NavController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = Permission::query()
            ->whereNotNull('nav_label')
            ->whereNotNull('nav_url')
            ->with('group')
            ->orderBy('nav_order')
            ->get()
            ->filter(fn (Permission $permission) => $user->hasPermissionTo($permission->permission_key))
            ->values()
            ->map(fn (Permission $permission) => [
                'key' => $permission->permission_key,
                'label' => $permission->nav_label,
                'url' => $permission->nav_url,
                'icon' => $permission->nav_icon,
                'group' => $permission->group?->name,
                'order' => $permission->nav_order,
            ]);

        return response()->json(['data' => $items]);
    }
}
