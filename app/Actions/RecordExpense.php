<?php

namespace App\Actions;

use App\Models\Bus;
use App\Models\CashCountHistory;
use App\Models\OpExpense;
use App\Models\User;
use App\Support\Denominations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a cash operational expense for a bus / operating-date / shift —
 * BITS `admin/process/expenses/add.php` (docs/MIGRATION_MAP.md §4.4). The
 * amount is the sum of the denominations handed out; the ledger notes the
 * cash leaving the drawer and the rollup is re-netted.
 */
class RecordExpense
{
    public function __construct(private readonly RollUpBusDayCashCount $rollUp) {}

    /**
     * @param  array{bus_id:int, op_date:string, shift:string, category:string, description:string, q1000?:int, q500?:int, q200?:int, q100?:int, q50?:int, q20?:int, q10?:int, q5?:int, q1?:int}  $input
     */
    public function handle(User $actor, array $input): OpExpense
    {
        $bus = Bus::query()->find($input['bus_id']);
        if ($bus === null) {
            throw ValidationException::withMessages(['bus_id' => 'That bus is not in your fleet.']);
        }

        $den = Denominations::normalize($input);
        $amount = Denominations::total($den);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'An expense must be more than ₱0.']);
        }

        return DB::transaction(function () use ($actor, $bus, $input, $den, $amount): OpExpense {
            $expense = OpExpense::query()->create([
                'company_id' => $actor->company_id,
                'bus_id' => $bus->id,
                'op_date' => $input['op_date'],
                'shift' => $input['shift'],
                'category' => $input['category'],
                'description' => $input['description'],
                'amount' => $amount,
                ...$den,
                'status' => 'Active',
                'recorded_by' => $actor->id,
                'recorded_by_name' => $actor->name,
                'recorded_at' => now(),
            ]);

            CashCountHistory::query()->create([
                'company_id' => $actor->company_id,
                'bus_id' => $bus->id,
                'op_date' => $input['op_date'],
                'shift' => $input['shift'],
                'type' => 'Expense',
                'ref_code' => 'EXP-'.$expense->id,
                'description' => "{$input['category']}: {$input['description']}",
                'amount' => -1 * $amount,
                ...$den,
                'recorded_by' => $actor->id,
                'recorded_by_name' => $actor->name,
            ]);

            $this->rollUp->handle($actor->company_id, $bus->id, $input['op_date'], $input['shift']);

            return $expense;
        });
    }
}
