<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only ledger row — BITS `cash_count_history`. Never updated or
 * deleted; written by `App\Actions\RecordCashCount` / `VoidCashCount` and
 * (later) the expense actions.
 */
class CashCountHistory extends Model
{
    use BelongsToCompany;

    protected $table = 'cash_count_history';

    public const UPDATED_AT = null;

    public const TYPES = ['RemitReceived', 'RemitVoid', 'Expense', 'ExpenseVoid', 'Adjustment'];

    protected $fillable = [
        'company_id', 'cash_count_id', 'trip_id', 'bus_id', 'op_date', 'shift', 'type',
        'ref_code', 'description', 'amount',
        'q1000', 'q500', 'q200', 'q100', 'q50', 'q20', 'q10', 'q5', 'q1',
        'recorded_by', 'recorded_by_name', 'authorized_by', 'authorized_by_name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'op_date' => 'date',
            'amount' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CashCount, $this>
     */
    public function cashCount(): BelongsTo
    {
        return $this->belongsTo(CashCount::class);
    }
}
