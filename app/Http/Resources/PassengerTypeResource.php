<?php

namespace App\Http\Resources;

use App\Models\PassengerType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PassengerType
 */
class PassengerTypeResource extends JsonResource
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
            'fare_mode' => $this->fare_mode,
            'discount_percent' => (float) $this->discount_percent,
            'sort_order' => $this->sort_order,
            'status' => $this->status,
            'articles_count' => $this->whenCounted('articles'),
            'articles' => PassengerTypeArticleResource::collection($this->whenLoaded('articles')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
