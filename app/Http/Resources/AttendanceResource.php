<?php

namespace App\Http\Resources;

use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Attendance
 */
class AttendanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $end = $this->clock_out_at ?? now();

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id, 'name' => $this->user->name,
            ] : null),
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
