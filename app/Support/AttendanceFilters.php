<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The Attendance page's filters, applied identically to the conductor list
 * (conductor_attendance) and the driver list (driver_attendance):
 *
 *   from / to   clock-in date range (inclusive); `date` = a single day (older callers)
 *   status      on_shift | clocked_out | force_closed  (`open=1` = on_shift, older callers)
 *   source      app | web — where the clock-in came from
 *   person      handled by each caller (conductor_id / driver_id)
 *   q           handled by each caller (name search)
 */
final class AttendanceFilters
{
    /**
     * @param  Builder<Model>  $query
     */
    public static function apply(Builder $query, Request $request): void
    {
        $query
            ->when($request->filled('date'), fn ($q) => $q->whereDate('clock_in_at', $request->date('date')))
            ->when($request->filled('from'), fn ($q) => $q->where('clock_in_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('clock_in_at', '<=', $request->date('to')->endOfDay()))
            ->when($request->filled('source'), fn ($q) => $q->where('clock_in_source', $request->string('source')->value()));

        $status = $request->boolean('open') ? 'on_shift' : $request->string('status')->value();
        match ($status) {
            'on_shift' => $query->whereNull('clock_out_at'),
            'clocked_out' => $query->whereNotNull('clock_out_at'),
            'force_closed' => $query->whereNotNull('closed_by'),
            default => null,
        };
    }
}
