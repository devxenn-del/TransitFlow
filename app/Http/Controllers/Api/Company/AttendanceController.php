<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CloseAttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Company-side attendance oversight — BITS `admin/attendance.php`. Rows are
 * constrained to the caller's company by CompanyScope; capability is gated
 * by `permission:attendance.*` on the routes.
 */
class AttendanceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $periods = Attendance::query()
            ->with(['user', 'closedBy'])
            ->when($request->filled('conductor_id'), fn ($q) => $q->where('user_id', $request->integer('conductor_id')))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('clock_in_at', $request->date('date')))
            ->when($request->boolean('open'), fn ($q) => $q->open())
            ->orderByDesc('clock_in_at')
            ->paginate($request->integer('per_page', 20));

        return AttendanceResource::collection($periods);
    }

    public function close(CloseAttendanceRequest $request, Attendance $attendance): AttendanceResource
    {
        if (! $attendance->isOpen()) {
            throw ValidationException::withMessages(['attendance' => 'This period is already closed.']);
        }

        $closeAt = $request->filled('clock_out_at') ? $request->date('clock_out_at') : now();

        if ($closeAt < $attendance->clock_in_at) {
            throw ValidationException::withMessages(['clock_out_at' => 'Clock-out cannot be before clock-in.']);
        }

        $attendance->update([
            'clock_out_at' => $closeAt,
            'clock_out_source' => 'web',
            'closed_by' => $request->user()->id,
            'closed_note' => $request->string('note')->value(),
        ]);

        return AttendanceResource::make($attendance->fresh()->load(['user', 'closedBy']));
    }
}
