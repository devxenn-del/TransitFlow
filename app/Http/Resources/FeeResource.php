<?php

namespace App\Http\Resources;

use App\Models\Fee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A fee as the Super Admin manages it (standard pricing). Super Admin only.
 *
 * @mixin Fee
 */
class FeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'amount' => (string) $this->amount,
            'billing_frequency' => $this->billing_frequency->value,
            'billing_interval_months' => $this->billing_interval_months,
            'frequency_label' => $this->frequencyLabel(),
            'applies_to_all_companies' => $this->applies_to_all_companies,
            'is_active' => $this->is_active,
            'effective_date' => $this->effective_date->toDateString(),
            'assigned_companies_count' => $this->whenCounted('assignedCompanies'),
            'special_rates_count' => $this->whenCounted('specialRates'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
