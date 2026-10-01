<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Excess / short payments, carried to the next bill.
 *
 *  - `amount_received` — what the company actually paid (set on "mark paid").
 *  - `carry_over_amount` — total − received: positive = SHORT (added to the
 *    next bill), negative = EXCESS (deducted from it), 0 = exact.
 *  - `carried_to_statement_id` — the later statement that took the carry-over
 *    as a line; null while it is still waiting for the next bill.
 *
 * Statement lines gain a `kind` (`fee` | `carry_over`) and the source
 * statement of a carry-over line; a carry-over line has no frequency or
 * rate type. Existing paid statements are treated as paid in full.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_statements', function (Blueprint $table) {
            $table->decimal('amount_received', 12, 2)->nullable()->after('paid_at');
            $table->decimal('carry_over_amount', 12, 2)->default(0)->after('amount_received');
            $table->foreignId('carried_to_statement_id')->nullable()->after('carry_over_amount')
                ->constrained('billing_statements')->nullOnDelete();
        });

        Schema::table('billing_statement_items', function (Blueprint $table) {
            $table->string('kind', 20)->default('fee')->after('billing_statement_id');
            $table->foreignId('source_statement_id')->nullable()->after('fee_id')
                ->constrained('billing_statements')->nullOnDelete();
            $table->string('billing_frequency', 20)->nullable()->change();
            $table->string('pricing_type', 20)->nullable()->change();
        });

        DB::table('billing_statements')->where('status', 'paid')->update(['amount_received' => DB::raw('total')]);
    }

    public function down(): void
    {
        Schema::table('billing_statement_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_statement_id');
            $table->dropColumn('kind');
        });

        Schema::table('billing_statements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('carried_to_statement_id');
            $table->dropColumn(['amount_received', 'carry_over_amount']);
        });
    }
};
