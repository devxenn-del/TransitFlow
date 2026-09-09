<?php

namespace App\Http\Resources;

use App\Models\RemittanceCashCount;
use App\Support\Denominations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RemittanceCashCount
 */
class RemittanceCashCountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trip_id' => $this->trip_id,
            'bus_id' => $this->bus_id,
            'op_date' => $this->op_date?->toDateString(),
            'shift' => $this->shift,
            'denominations' => Denominations::normalize($this->attributesToArray()),
            'counted_total' => (int) $this->counted_total,
            'expected_amount' => (int) $this->expected_amount,
            'variance' => (int) $this->variance,
            'status' => $this->status,
            'received_by' => $this->received_by_name,
            'received_at' => $this->received_at,
            'voided_by' => $this->whenLoaded('voidedBy', fn () => $this->voidedBy?->name),
            'voided_at' => $this->voided_at,
            'void_reason' => $this->void_reason,
        ];
    }
}
