<?php

namespace App\Actions;

use App\Enums\BillingStatementStatus;
use App\Models\BillingStatement;
use App\Models\BillingStatementItem;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a billing statement's payment state.
 *
 * Marking it PAID records the amount actually received; the difference is
 * the carry-over the company's next bill picks up (App\Actions\GenerateBillingStatement):
 *   received > total → EXCESS, deducted from the next bill (carry-over < 0)
 *   received < total → SHORT, added to the next bill (carry-over > 0)
 *
 * Guards keep carried balances counted exactly once: a paid statement whose
 * excess/shortage is already on a later bill can't be reopened or voided,
 * and voiding a bill that carried balances releases them to the next bill.
 */
class UpdateBillingStatementStatus
{
    /**
     * @param  string|null  $amountReceived  only for PAID; defaults to the statement total (paid exactly)
     * @return bool whether the status actually changed (false for a repeat of the current status)
     *
     * @throws ValidationException when the change would double-count or lose a carried balance
     */
    public function handle(BillingStatement $statement, BillingStatementStatus $to, ?string $amountReceived = null, ?User $actor = null): bool
    {
        $changes = DB::transaction(function () use ($statement, $to, $amountReceived): ?array {
            // Lock the row: of two simultaneous requests only one makes the change.
            $locked = BillingStatement::query()->withoutCompanyScope()->with('carriedTo')->lockForUpdate()->findOrFail($statement->id);
            $from = $locked->status;

            if ($from === $to) {
                return null;
            }

            if ($from === BillingStatementStatus::Paid && $locked->carriedTo !== null) {
                throw ValidationException::withMessages([
                    'status' => 'Its '.($locked->carry_over_amount > 0 ? 'shortage' : 'excess payment')." is already on Billing No. {$locked->carriedTo->billing_number} — it can no longer be changed.",
                ]);
            }

            if ($from === BillingStatementStatus::Void && $locked->items()->where('kind', BillingStatementItem::KIND_CARRY_OVER)->exists()) {
                throw ValidationException::withMessages([
                    'status' => 'This void statement carried earlier balances, which were released to the next bill — it can\'t be reopened.',
                ]);
            }

            $attributes = match ($to) {
                BillingStatementStatus::Paid => (function () use ($locked, $amountReceived): array {
                    $received = $amountReceived ?? (string) $locked->total;

                    return [
                        'paid_at' => now(),
                        'amount_received' => $received,
                        'carry_over_amount' => round((float) $locked->total - (float) $received, 2),
                    ];
                })(),
                default => ['paid_at' => null, 'amount_received' => null, 'carry_over_amount' => 0],
            };

            $locked->update(['status' => $to, ...$attributes]);

            // A voided bill no longer collects the balances it carried — the next bill does.
            if ($to === BillingStatementStatus::Void) {
                BillingStatement::query()->withoutCompanyScope()
                    ->where('carried_to_statement_id', $locked->id)
                    ->update(['carried_to_statement_id' => null]);
            }

            return ['from' => $from->value, 'to' => $to->value];
        });

        $statement->refresh();

        if ($changes === null) {
            return false;
        }

        Audit::record('billing.statement.status_changed', $statement, [
            'billing_number' => $statement->billing_number,
            'changes' => ['status' => $changes],
            ...($to === BillingStatementStatus::Paid ? [
                'total' => (string) $statement->total,
                'amount_received' => (string) $statement->amount_received,
                'carry_over' => (string) $statement->carry_over_amount,
            ] : []),
        ], company: $statement->company, actor: $actor);

        return true;
    }
}
