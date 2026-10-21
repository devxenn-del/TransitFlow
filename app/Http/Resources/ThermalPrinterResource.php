<?php

namespace App\Http\Resources;

use App\Enums\ThermalPrinterStatus;
use App\Models\ThermalPrinter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One thermal printer — used by both the Super Admin inventory and the
 * company's read-only list. Inventory-only details (remarks, status
 * options, history) are sent to the Super Admin alone.
 *
 * @mixin ThermalPrinter
 */
class ThermalPrinterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isSuperAdmin = (bool) $request->user()?->isSuperAdmin();

        return [
            'id' => $this->id,
            'thermal_id' => $this->thermal_id,
            'device_id' => $this->device_id,
            'serial_number' => $this->serial_number,
            'mac_address' => $this->mac_address,
            'model' => $this->model,
            'status' => $this->status->value,
            'date_issued' => $this->date_issued?->toDateString(),
            'company' => $this->whenLoaded('company', fn () => $this->company ? [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'code' => $this->company->code,
            ] : null),
            'bus' => $this->whenLoaded('bus', fn () => $this->bus ? [
                'id' => $this->bus->id,
                'bus_number' => $this->bus->bus_number,
                'plate_number' => $this->bus->plate_number,
                'status' => $this->bus->status,
            ] : null),
            'remarks' => $this->when($isSuperAdmin, $this->remarks),
            'status_options' => $this->when($isSuperAdmin, fn () => array_map(
                fn (ThermalPrinterStatus $status) => $status->value,
                $this->statusOptions(),
            )),
            'history' => $this->when($isSuperAdmin && $this->relationLoaded('assignments'), fn () => ThermalPrinterAssignmentResource::collection($this->assignments)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
