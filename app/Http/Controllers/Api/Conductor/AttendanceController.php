<?php

namespace App\Http\Controllers\Api\Conductor;

use App\Actions\ConfirmShiftEnd;
use App\Actions\ToggleAttendance;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conductor\ConfirmShiftEndRequest;
use App\Http\Requests\Conductor\ToggleAttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authenticated conductor's own attendance — BITS `api/attendance/*`.
 * The user is always the caller; the id is never taken from the request.
 */
class AttendanceController extends Controller
{
    /** GET /api/conductor/attendance — the open period (or null) + today's log. */
    public function show(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $open = Attendance::query()->forUser($userId)->open()->latest('clock_in_at')->first();

        $today = Attendance::query()
            ->forUser($userId)
            ->whereDate('clock_in_at', now())
            ->with('closedBy')
            ->orderByDesc('clock_in_at')
            ->get();

        return response()->json([
            'open' => $open ? AttendanceResource::make($open) : null,
            'today' => AttendanceResource::collection($today),
        ]);
    }

    public function toggle(ToggleAttendanceRequest $request, ToggleAttendance $action): JsonResponse
    {
        $period = $action->handle($request->user(), $request->input('source', 'web'));

        return response()->json([
            'data' => AttendanceResource::make($period),
            'clocked_in' => $period->isOpen(),
        ], JsonResponse::HTTP_CREATED);
    }

    /**
     * Confirm end of shift (BITS `api/trips/confirmShiftEnd.php`): re-verify
     * the PIN, lock the account ('shift_end'), and Clock out. See
     * App\Actions\ConfirmShiftEnd.
     */
    public function confirmShiftEnd(ConfirmShiftEndRequest $request, ConfirmShiftEnd $action): JsonResponse
    {
        $action->handle($request->user(), $request->string('pin')->value());

        return response()->json([
            'message' => 'Shift ended. You have been clocked out and this bus is now available for your next sign-in.',
        ]);
    }
}
