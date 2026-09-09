<?php

namespace App\Http\Resources;

use App\Models\AdminBusAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AdminBusAssignment
 */
class AdminBusAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bus' => $this->whenLoaded('bus', fn () => $this->bus ? [
                'id' => $this->bus->id,
                'bus_number' => $this->bus->bus_number,
            ] : null),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'assigned_by' => $this->whenLoaded('assignedBy', fn () => $this->assignedBy?->name),
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'shift' => $this->shift,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
