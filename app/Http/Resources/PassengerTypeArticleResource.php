<?php

namespace App\Http\Resources;

use App\Models\PassengerTypeArticle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PassengerTypeArticle
 */
class PassengerTypeArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'passenger_type_id' => $this->passenger_type_id,
            'label' => $this->label,
            'amount' => (float) $this->amount,
            'sort_order' => $this->sort_order,
            'status' => $this->status,
        ];
    }
}
