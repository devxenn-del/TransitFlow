<?php

namespace App\Console\Commands;

use App\Actions\GenerateBillingStatement;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('billing:generate {--period= : Bill every active company for its period starting in this month (YYYY-MM)}')]
#[Description('Generate billing statements: by default, for every active company whose billing period ended yesterday')]
class GenerateBillingStatements extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(GenerateBillingStatement $generator): int
    {
        $forcedMonth = null;

        if ($this->option('period')) {
            $forcedMonth = CarbonImmutable::createFromFormat('!Y-m', $this->option('period'));

            if (! $forcedMonth) {
                $this->error('The --period must be in YYYY-MM format.');

                return self::FAILURE;
            }
        }

        $yesterday = CarbonImmutable::yesterday();
        $generated = 0;

        Company::query()->active()->orderBy('id')->each(function (Company $company) use ($generator, $forcedMonth, $yesterday, &$generated): void {
            if ($forcedMonth) {
                $month = $forcedMonth;
            } else {
                // Each company is billed the day after its own period ends.
                [$periodStart, $periodEnd] = $company->billingPeriodContaining($yesterday);

                if (! $periodEnd->isSameDay($yesterday)) {
                    return;
                }

                $month = $periodStart;
            }

            try {
                $statement = $generator->handle($company, $month);
                $generated++;
                $this->line("  {$company->code} #{$statement->billing_number}: ₱{$statement->total}");
            } catch (ValidationException $e) {
                $this->line("  skipped {$company->name}: ".collect($e->errors())->flatten()->first());
            }
        });

        $this->info("Generated {$generated} billing statement(s).");

        return self::SUCCESS;
    }
}
