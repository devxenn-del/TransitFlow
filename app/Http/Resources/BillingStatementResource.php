<?php

namespace App\Http\Resources;

use App\Models\BillingStatement;
use App\Models\BillingStatementItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A billing statement with its snapshotted lines. The standard amount
 * behind a special-rate line is shown to the Super Admin only.
 *
 * @mixin BillingStatement
 */
class BillingStatementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isSuperAdmin = (bool) $request->user()?->isSuperAdmin();

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'reference' => $this->reference,
            'billing_number' => $this->billing_number,
            'company_code' => $this->whenLoaded('company', fn () => $this->company->code),
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'issued_on' => $this->issued_on->toDateString(),
            'due_on' => $this->due_on->toDateString(),
            'total' => (string) $this->total,
            'status' => $this->status->value,
            'paid_at' => $this->paid_at,
            'amount_received' => $this->amount_received === null ? null : (string) $this->amount_received,
            // + short (added to the next bill) / − excess (deducted from it)
            'carry_over_amount' => (string) $this->carry_over_amount,
            'carried_to_billing_number' => $this->whenLoaded('carriedTo', fn () => $this->carriedTo?->billing_number),
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (BillingStatementItem $item) => [
                'id' => $item->id,
                'kind' => $item->kind,
                'fee_name' => $item->fee_name,
                'fee_description' => $item->fee_description,
                'frequency_label' => $item->billing_frequency?->label($item->billing_interval_months),
                'pricing_type' => $item->pricing_type?->value,
                'rate_label' => $item->pricing_type?->rateLabel(),
                'amount' => (string) $item->amount,
                ...($isSuperAdmin && ! $item->isCarryOver() ? ['standard_amount' => (string) $item->standard_amount] : []),
            ])->all()),
            'created_at' => $this->created_at,
        ];
    }
}
