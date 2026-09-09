<?php

namespace App\Actions;

use App\Models\CashCountHistory;
use App\Models\OpExpense;
use App\Models\User;
use App\Support\ManagerVoidPin;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Voids an operational expense — BITS `admin/process/expenses/void.php`
 * (docs/MIGRATION_MAP.md §4.4). Manager-only, gated by the void-PIN.
 * Reversing it restores the cash to the drawer; the ledger records a
 * matching positive `ExpenseVoid` entry and the rollup is re-netted.
 */
class VoidExpense
{
    public function __construct(private readonly RollUpBusDayCashCount $rollUp) {}

    public function handle(User $manager, OpExpense $expense, string $reason, ?string $pin): OpExpense
    {
        if (! $expense->isActive()) {
            throw ValidationException::withMessages(['expense' => 'This expense is already voided.']);
        }

        ManagerVoidPin::authorize($manager, $pin, 'expense:'.$expense->id);

        return DB::transaction(function () use ($manager, $expense, $reason): OpExpense {
            $expense->update([
                'status' => 'Voided',
                'voided_by' => $manager->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            ManagerVoidPin::log($manager, 'expense:'.$expense->id, true, 'Expense void authorized');

            CashCountHistory::query()->create([
                'company_id' => $expense->company_id,
                'bus_id' => $expense->bus_id,
                'op_date' => $expense->op_date,
                'shift' => $expense->shift,
                'type' => 'ExpenseVoid',
                'ref_code' => 'EXP-'.$expense->id.'-VOID',
                'description' => 'Void: '.$reason,
                'amount' => (int) $expense->amount, // cash restored
                ...$expense->denominations(),
                'recorded_by' => $manager->id,
                'recorded_by_name' => $manager->name,
                'authorized_by' => $manager->id,
                'authorized_by_name' => $manager->name,
            ]);

            $this->rollUp->handle(
                $expense->company_id,
                $expense->bus_id,
                $expense->op_date->toDateString(),
                $expense->shift,
            );

            return $expense->refresh();
        });
    }
}
