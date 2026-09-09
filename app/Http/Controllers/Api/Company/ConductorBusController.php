<?php

namespace App\Http\Controllers\Api\Company;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\AssignConductorBusesRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Assigns buses to a conductor account (BITS: Fleet Management → Accounts →
 * Assigned Bus). A conductor can run trips on any bus assigned here.
 */
class ConductorBusController extends Controller
{
    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return response()->json([
            'data' => $user->buses()->orderBy('bus_number')->get(['buses.id', 'bus_number', 'plate_number', 'status']),
        ]);
    }

    public function sync(AssignConductorBusesRequest $request, User $user): JsonResponse
    {
        if ($user->role !== UserRole::CompanyUser) {
            throw ValidationException::withMessages(['bus_ids' => 'Only company (conductor) accounts can be assigned buses.']);
        }

        $user->buses()->sync($request->validated('bus_ids'));

        return response()->json([
            'data' => $user->buses()->orderBy('bus_number')->get(['buses.id', 'bus_number', 'plate_number', 'status']),
        ]);
    }
}
