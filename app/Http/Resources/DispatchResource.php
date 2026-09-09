<?php

namespace App\Http\Resources;

use App\Models\Dispatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Dispatch
 */
class DispatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trip_id' => $this->trip_id,
            'barker_name' => $this->barker_name,
            'amount' => (float) $this->amount,
            'dispatched_at' => $this->dispatched_at,
            'created_at' => $this->created_at,
        ];
    }
}
