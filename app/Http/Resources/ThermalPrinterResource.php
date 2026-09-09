<?php

namespace App\Http\Resources;

use App\Models\ThermalPrinter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ThermalPrinter
 */
class ThermalPrinterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'mac_address' => $this->mac_address,
            'model' => $this->model,
            'status' => $this->status,
            'holder' => $this->whenLoaded('holder', fn () => $this->holder ? [
                'id' => $this->holder->id,
                'name' => $this->holder->name,
            ] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
