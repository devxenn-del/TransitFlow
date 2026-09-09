<?php

namespace App\Http\Resources;

use App\Models\Route;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Route
 */
class RouteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'origin' => $this->origin,
            'destination' => $this->destination,
            'status' => $this->status,
            'fare' => FareMatrixResource::make($this->whenLoaded('fareMatrix')),
            'is_priced' => $this->when(
                $this->relationLoaded('fareMatrix'),
                fn () => $this->isPriced(),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
