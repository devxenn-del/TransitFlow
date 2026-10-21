<?php

namespace App\Http\Resources;

use App\Models\ThermalPrinterAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ThermalPrinterAssignment
 */
class ThermalPrinterAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'from_company' => $this->fromCompany?->name,
            'to_company' => $this->toCompany?->name,
            'from_bus' => $this->from_bus_label,
            'to_bus' => $this->to_bus_label,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'remarks' => $this->remarks,
            'user_name' => $this->user_name,
            'created_at' => $this->created_at,
        ];
    }
}
