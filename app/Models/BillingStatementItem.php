<?php

namespace App\Models;

use App\Enums\BillingFrequency;
use App\Enums\PricingPlan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a billing statement: a fee (a snapshot of its name, frequency
 * and the amount charged at generation time) or a carry-over of a previous
 * bill's shortage / excess payment.
 */
class BillingStatementItem extends Model
{
    /** A fee charged for the period. */
    public const KIND_FEE = 'fee';

    /** A previous bill's shortage (+) or excess payment (−) carried to this bill. */
    public const KIND_CARRY_OVER = 'carry_over';

    protected $fillable = [
        'billing_statement_id',
        'kind',
        'fee_id',
        'source_statement_id',
        'fee_name',
        'fee_description',
        'billing_frequency',
        'billing_interval_months',
        'pricing_type',
        'standard_amount',
        'amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billing_frequency' => BillingFrequency::class,
            'billing_interval_months' => 'integer',
            'pricing_type' => PricingPlan::class,
            'standard_amount' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function isCarryOver(): bool
    {
        return $this->kind === self::KIND_CARRY_OVER;
    }

    /**
     * @return BelongsTo<BillingStatement, $this>
     */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(BillingStatement::class, 'billing_statement_id');
    }
}
