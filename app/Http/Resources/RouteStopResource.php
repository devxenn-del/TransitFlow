<?php

namespace App\Http\Resources;

use App\Models\RouteStop;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RouteStop
 */
class RouteStopResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'franchise_id' => $this->franchise_id,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'status' => $this->status,
        ];
    }
}
