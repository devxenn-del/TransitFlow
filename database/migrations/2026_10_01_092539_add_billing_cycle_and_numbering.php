<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company billing cycle and statement numbering:
 *
 *  - `companies.billing_cycle_day` — the day each billing period starts
 *    (e.g. 24 → 07/24–08/23). Capped at 28 so every month has it. Existing
 *    companies default to the day they registered.
 *  - `companies.next_billing_number` — the company's next statement number
 *    (its own sequence; the Super Admin may set it to continue an earlier one).
 *  - `billing_statements.billing_number` — that number, unique per company.
 *    Existing statements are numbered in period order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedTinyInteger('billing_cycle_day')->default(1)->after('pricing_plan');
            $table->unsignedInteger('next_billing_number')->default(1)->after('billing_cycle_day');
        });

        Schema::table('billing_statements', function (Blueprint $table) {
            $table->unsignedInteger('billing_number')->nullable()->after('reference');
        });

        DB::table('companies')->orderBy('id')->each(function (object $company): void {
            DB::table('companies')->where('id', $company->id)->update([
                'billing_cycle_day' => min((int) date('j', strtotime($company->created_at ?? 'now')), 28),
            ]);

            $number = 0;
            DB::table('billing_statements')->where('company_id', $company->id)->orderBy('period_start')->orderBy('id')->get(['id'])
                ->each(function (object $statement) use (&$number): void {
                    DB::table('billing_statements')->where('id', $statement->id)->update(['billing_number' => ++$number]);
                });

            DB::table('companies')->where('id', $company->id)->update(['next_billing_number' => $number + 1]);
        });

        Schema::table('billing_statements', function (Blueprint $table) {
            $table->unsignedInteger('billing_number')->nullable(false)->change();
            $table->unique(['company_id', 'billing_number']);
        });
    }

    public function down(): void
    {
        Schema::table('billing_statements', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'billing_number']);
            $table->dropColumn('billing_number');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['billing_cycle_day', 'next_billing_number']);
        });
    }
};
