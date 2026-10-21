<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CloseAttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\DriverAttendanceResource;
use App\Models\Attendance;
use App\Models\DriverAttendance;
use App\Support\AttendanceFilters;
use App\Support\DriverAttendanceSync;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Company-side attendance oversight — BITS `admin/attendance.php`, for
 * conductors and (since drivers clock in with their conductor —
 * App\Support\DriverAttendanceSync) drivers. Both lists take the same filters
 * (App\Support\AttendanceFilters). Rows are constrained to the caller's
 * company by CompanyScope; capability is gated by `permission:attendance.*`.
 */
class AttendanceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = $request->string('q')->trim()->value();

        $query = Attendance::query()
            ->with(['user', 'closedBy', 'driverPeriods.driver'])
            ->when($request->filled('conductor_id'), fn ($b) => $b->where('user_id', $request->integer('conductor_id')))
            ->when($q !== '', fn ($b) => $b->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%")));
        AttendanceFilters::apply($query, $request);

        return AttendanceResource::collection(
            $query->orderByDesc('clock_in_at')->paginate($request->integer('per_page', 20))
        );
    }

    /** GET company/driver-attendance — driver clock-in periods, same filters. */
    public function drivers(Request $request): AnonymousResourceCollection
    {
        $q = $request->string('q')->trim()->value();

        $query = DriverAttendance::query()
            ->with(['driver', 'conductor', 'closedBy'])
            ->when($request->filled('driver_id'), fn ($b) => $b->where('driver_id', $request->integer('driver_id')))
            ->when($request->filled('conductor_id'), fn ($b) => $b->where('conductor_id', $request->integer('conductor_id')))
            ->when($q !== '', fn ($b) => $b->whereHas('driver', fn ($d) => $d
                ->where('name', 'like', "%{$q}%")
                ->orWhere('driver_code', 'like', "%{$q}%")));
        AttendanceFilters::apply($query, $request);

        return DriverAttendanceResource::collection(
            $query->orderByDesc('clock_in_at')->paginate($request->integer('per_page', 20))
        );
    }

    /** Force-close a conductor's shift — the driver on it is clocked out at the same time. */
    public function close(CloseAttendanceRequest $request, Attendance $attendance): AttendanceResource
    {
        if (! $attendance->isOpen()) {
            throw ValidationException::withMessages(['attendance' => 'This period is already closed.']);
        }

        $closeAt = $request->filled('clock_out_at') ? $request->date('clock_out_at') : now();

        if ($closeAt < $attendance->clock_in_at) {
            throw ValidationException::withMessages(['clock_out_at' => 'Clock-out cannot be before clock-in.']);
        }

        $note = $request->string('note')->value();
        $attendance->update([
            'clock_out_at' => $closeAt,
            'clock_out_source' => 'web',
            'closed_by' => $request->user()->id,
            'closed_note' => $note,
        ]);
        DriverAttendanceSync::closeFor($attendance, $closeAt, 'web', $request->user(), $note);

        return AttendanceResource::make($attendance->fresh()->load(['user', 'closedBy', 'driverPeriods.driver']));
    }

    /** Force-close one driver's period (e.g. went home mid-shift) — the conductor stays on shift. */
    public function closeDriver(CloseAttendanceRequest $request, DriverAttendance $driverAttendance): DriverAttendanceResource
    {
        if (! $driverAttendance->isOpen()) {
            throw ValidationException::withMessages(['attendance' => 'This period is already closed.']);
        }

        $closeAt = $request->filled('clock_out_at') ? $request->date('clock_out_at') : now();
        if ($closeAt < $driverAttendance->clock_in_at) {
            throw ValidationException::withMessages(['clock_out_at' => 'Clock-out cannot be before clock-in.']);
        }

        $driverAttendance->update([
            'clock_out_at' => $closeAt,
            'clock_out_source' => 'web',
            'closed_by' => $request->user()->id,
            'closed_note' => $request->string('note')->value(),
        ]);

        return DriverAttendanceResource::make($driverAttendance->fresh()->load(['driver', 'conductor', 'closedBy']));
    }
}
