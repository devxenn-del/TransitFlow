<?php

namespace App\Http\Resources;

use App\Models\Franchise;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Franchise
 */
class FranchiseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'applicant_name' => $this->applicant_name,
            'route_description' => $this->route_description,
            'route_origin' => $this->route_origin,
            'route_destination' => $this->route_destination,
            'case_no' => $this->case_no,
            'status' => $this->status,
            'stops_count' => $this->whenCounted('stops'),
            'routes_count' => $this->whenCounted('routes'),
            'priced_routes_count' => $this->whenCounted('priced_routes'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
