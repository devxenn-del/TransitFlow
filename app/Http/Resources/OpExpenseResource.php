<?php

namespace App\Http\Resources;

use App\Models\OpExpense;
use App\Support\Denominations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OpExpense
 */
class OpExpenseResource extends JsonResource
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
            'category' => $this->category,
            'description' => $this->description,
            'amount' => (int) $this->amount,
            'denominations' => Denominations::normalize($this->attributesToArray()),
            'status' => $this->status,
            'recorded_by' => $this->recorded_by_name,
            'recorded_at' => $this->recorded_at,
            'voided_by' => $this->whenLoaded('voidedBy', fn () => $this->voidedBy?->name),
            'voided_at' => $this->voided_at,
            'void_reason' => $this->void_reason,
        ];
    }
}
