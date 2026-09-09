<?php

namespace App\Http\Resources;

use App\Models\FareMatrix;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FareMatrix
 */
class FareMatrixResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'route_id' => $this->route_id,
            'amount' => $this->amount,
            'discounted_amount' => $this->discounted_amount,
            'status' => $this->status,
            'route' => RouteResource::make($this->whenLoaded('route')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
