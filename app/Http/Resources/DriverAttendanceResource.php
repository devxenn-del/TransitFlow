<?php

namespace App\Http\Resources;

use App\Models\DriverAttendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A driver's clock-in period for the web Attendance page's Drivers tab.
 *
 * @mixin DriverAttendance
 */
class DriverAttendanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $end = $this->clock_out_at ?? now();

        return [
            'id' => $this->id,
            'driver_id' => $this->driver_id,
            'driver' => $this->whenLoaded('driver', fn () => $this->driver ? [
                'id' => $this->driver->id, 'name' => $this->driver->name, 'driver_code' => $this->driver->driver_code,
            ] : null),
            'conductor' => $this->whenLoaded('conductor', fn () => $this->conductor ? [
                'id' => $this->conductor->id, 'name' => $this->conductor->name,
            ] : null),
            'conductor_attendance_id' => $this->conductor_attendance_id,
            'clock_in_at' => $this->clock_in_at,
            'clock_out_at' => $this->clock_out_at,
            'clock_in_source' => $this->clock_in_source,
            'clock_out_source' => $this->clock_out_source,
            'is_open' => $this->isOpen(),
            'duration_minutes' => $this->clock_in_at ? $this->clock_in_at->diffInMinutes($end) : null,
            'closed_by' => $this->whenLoaded('closedBy', fn () => $this->closedBy?->name),
            'closed_note' => $this->closed_note,
        ];
    }
}
