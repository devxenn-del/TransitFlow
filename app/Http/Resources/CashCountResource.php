<?php

namespace App\Http\Resources;

use App\Models\CashCount;
use App\Support\Denominations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CashCount
 */
class CashCountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bus_id' => $this->bus_id,
            'bus' => $this->whenLoaded('bus', fn () => $this->bus ? [
                'id' => $this->bus->id, 'bus_number' => $this->bus->bus_number,
            ] : null),
            'op_date' => $this->op_date?->toDateString(),
            'shift' => $this->shift,
            'trip_count' => (int) $this->trip_count,
            'expense_count' => (int) $this->expense_count,
            'remitted_total' => (int) $this->remitted_total,
            'counted_total' => (int) $this->counted_total,
            'expenses_total' => (int) $this->expenses_total,
            'net_cash' => (int) $this->net_cash,
            'variance' => (int) $this->variance,
            'denominations' => Denominations::normalize($this->attributesToArray()),
            'adjusted_at' => $this->adjusted_at,
            'adjusted_by' => $this->whenLoaded('adjustedBy', fn () => $this->adjustedBy?->name),
            'adjustment_reason' => $this->adjustment_reason,
        ];
    }
}
