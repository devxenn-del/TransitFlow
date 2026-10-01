<?php

namespace App\Actions;

use App\Enums\BillingStatementStatus;
use App\Models\BillingStatement;
use App\Models\BillingStatementItem;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit;
use App\Support\Billing\FeePricing;
use App\Support\Billing\ResolvedFee;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bills a company for one billing period — from its cycle day to the day
 * before it a month later (e.g. 07/24/2026 – 08/23/2026). Every fee that
 * applies to the company and has a charge date inside the period is billed,
 * priced by FeePricing on its charge date — so a special rate in effect
 * on that date is applied even if it started mid-period. The statement takes the
 * company's next billing number and falls due DUE_AFTER_DAYS after the
 * period ends.
 *
 * Each line SNAPSHOTS the fee name, frequency and charged amount, so later
 * changes to the fee or the company's special rate never alter this bill.
 * One statement per company per period.
 */
class GenerateBillingStatement
{
    /** Days after the period ends that a statement falls due. */
    public const DUE_AFTER_DAYS = 21;

    public function __construct(private FeePricing $pricing) {}

    /**
     * @param  CarbonInterface  $month  any date in the month the billing period STARTS in
     *
     * @throws ValidationException when the period hasn't started, is already billed, or nothing is due
     */
    public function handle(Company $company, CarbonInterface $month, ?User $actor = null): BillingStatement
    {
        [$periodStart, $periodEnd] = $company->billingPeriodStartingIn($month);
        $label = $periodStart->format('m/d/Y').'-'.$periodEnd->format('m/d/Y');

        if ($periodStart->isFuture()) {
            throw ValidationException::withMessages([
                'period' => "The billing period {$label} hasn't started yet.",
            ]);
        }

        if ($company->billingStatements()->withoutCompanyScope()->whereDate('period_start', $periodStart)->exists()) {
            throw ValidationException::withMessages([
                'period' => "{$company->name} has already been billed for {$label}.",
            ]);
        }

        $due = $this->pricing->dueForPeriod($company, $periodStart, $periodEnd);

        if ($due->isEmpty() && ! $this->awaitingCarryOvers($company)->exists()) {
            throw ValidationException::withMessages([
                'period' => "No fees are due from {$company->name} for {$label}.",
            ]);
        }

        $statement = DB::transaction(function () use ($company, $periodStart, $periodEnd, $due, $actor): BillingStatement {
            // Lock the company row so two runs can't take the same billing
            // number — or carry the same excess / shortage onto two bills.
            $locked = Company::query()->lockForUpdate()->findOrFail($company->id);
            $billingNumber = $locked->next_billing_number;
            $locked->update(['next_billing_number' => $billingNumber + 1]);

            // Excess payments (−) and shortages (+) from paid bills not yet carried.
            $carryOvers = $this->awaitingCarryOvers($company)->orderBy('period_start')->get();
            $carryLines = $carryOvers->map(fn (BillingStatement $source) => $this->carryOverLine($source))->all();

            $statement = $company->billingStatements()->create([
                'reference' => "{$company->code}-{$billingNumber}",
                'billing_number' => $billingNumber,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'issued_on' => today(),
                'due_on' => $periodEnd->addDays(self::DUE_AFTER_DAYS),
                'total' => $due->sum(fn (ResolvedFee $resolved) => (float) $resolved->amount)
                    + array_sum(array_column($carryLines, 'amount')),
                'status' => BillingStatementStatus::Unpaid,
                'generated_by' => $actor?->id,
            ]);

            $statement->items()->createMany([...$this->lineItems($due), ...$carryLines]);

            if ($carryOvers->isNotEmpty()) {
                BillingStatement::query()->withoutCompanyScope()->whereKey($carryOvers->modelKeys())
                    ->update(['carried_to_statement_id' => $statement->id]);
            }

            return $statement;
        });

        $company->refresh();

        Audit::record('billing.statement.generated', $statement, [
            'billing_number' => $statement->billing_number,
            'period' => $label,
            'total' => (string) $statement->total,
            'items' => $statement->items()->count(),
        ], company: $company, actor: $actor);

        return $statement;
    }

    /**
     * Re-prices an UNPAID statement for its own period with the current fees
     * and special rates (e.g. a special rate set after the bill was made),
     * replacing its lines and total. Billing number, period and due date stay
     * the same. Paid and void statements are history and are never touched.
     *
     * @throws ValidationException when the statement isn't unpaid or nothing would be due
     */
    public function recalculate(BillingStatement $statement, ?User $actor = null): BillingStatement
    {
        if ($statement->status !== BillingStatementStatus::Unpaid) {
            throw ValidationException::withMessages([
                'statement' => "Only an unpaid statement can be recalculated — Billing No. {$statement->billing_number} is {$statement->status->value}.",
            ]);
        }

        $company = $statement->company;
        $due = $this->pricing->dueForPeriod($company, $statement->period_start, $statement->period_end);

        if ($due->isEmpty() && ! $statement->items()->where('kind', BillingStatementItem::KIND_CARRY_OVER)->exists()) {
            throw ValidationException::withMessages([
                'statement' => 'No fees would be due for this period any more — void the statement instead.',
            ]);
        }

        $before = $statement->items()->get()->map(fn ($item) => "{$item->fee_name}: {$item->amount}")->all();
        $previousTotal = (string) $statement->total;

        DB::transaction(function () use ($statement, $due): void {
            // Serialize concurrent recalculations (and a racing "mark paid") on this statement.
            $locked = BillingStatement::query()->withoutCompanyScope()->lockForUpdate()->findOrFail($statement->id);

            if ($locked->status !== BillingStatementStatus::Unpaid) {
                throw ValidationException::withMessages(['statement' => 'This statement is no longer unpaid.']);
            }

            // Only the fee lines are re-priced; carried balances stay as they are.
            $statement->items()->where('kind', BillingStatementItem::KIND_FEE)->delete();
            $statement->items()->createMany($this->lineItems($due));
            $statement->update(['total' => $statement->items()->sum('amount')]);
        });

        $statement->refresh();

        Audit::record('billing.statement.recalculated', $statement, [
            'billing_number' => $statement->billing_number,
            'changes' => [
                'total' => ['from' => $previousTotal, 'to' => (string) $statement->total],
                'lines' => ['from' => $before, 'to' => $statement->items()->get()->map(fn ($item) => "{$item->fee_name}: {$item->amount}")->all()],
            ],
        ], company: $company, actor: $actor);

        return $statement;
    }

    /**
     * @return HasMany<BillingStatement, Company>
     */
    private function awaitingCarryOvers(Company $company): HasMany
    {
        return $company->billingStatements()->withoutCompanyScope()->awaitingCarryOver();
    }

    /**
     * A line carrying a paid bill's shortage (added) or excess payment
     * (deducted) onto this bill.
     *
     * @return array<string, mixed>
     */
    private function carryOverLine(BillingStatement $source): array
    {
        $amount = (float) $source->carry_over_amount;
        $php = fn (float|string $value): string => 'PHP'.number_format((float) $value, 2);

        return [
            'kind' => BillingStatementItem::KIND_CARRY_OVER,
            'source_statement_id' => $source->id,
            'fee_name' => ($amount > 0 ? 'Balance from Billing No. ' : 'Excess payment from Billing No. ').$source->billing_number,
            'fee_description' => "Billed {$php($source->total)}, received {$php($source->amount_received)}.",
            'billing_frequency' => null,
            'pricing_type' => null,
            'standard_amount' => $amount,
            'amount' => $amount,
        ];
    }

    /**
     * Statement lines snapshotting each fee and the amount charged.
     *
     * @param  Collection<int, ResolvedFee>  $due
     * @return list<array<string, mixed>>
     */
    private function lineItems(Collection $due): array
    {
        return $due->map(fn (ResolvedFee $resolved) => [
            'kind' => BillingStatementItem::KIND_FEE,
            'fee_id' => $resolved->fee->id,
            'fee_name' => $resolved->fee->name,
            'fee_description' => $resolved->fee->description,
            'billing_frequency' => $resolved->fee->billing_frequency,
            'billing_interval_months' => $resolved->fee->billing_interval_months,
            'pricing_type' => $resolved->pricingType,
            'standard_amount' => $resolved->fee->amount,
            'amount' => $resolved->amount,
        ])->values()->all();
    }
}
