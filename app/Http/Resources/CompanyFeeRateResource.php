<?php

namespace App\Http\Resources;

use App\Models\CompanyFeeRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A company's special rate for one fee, including the internal notes.
 * Super Admin only — never returned from a company endpoint.
 *
 * @mixin CompanyFeeRate
 */
class CompanyFeeRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'fee_id' => $this->fee_id,
            'fee' => $this->whenLoaded('fee', fn () => [
                'id' => $this->fee->id,
                'name' => $this->fee->name,
                'amount' => (string) $this->fee->amount,
                'frequency_label' => $this->fee->frequencyLabel(),
                'is_active' => $this->fee->is_active && ! $this->fee->trashed(),
            ]),
            'amount' => (string) $this->amount,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'is_active' => $this->is_active,
            'state' => $this->state(),
            'internal_notes' => $this->internal_notes,
            'updated_at' => $this->updated_at,
        ];
    }
}
