<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A manager may correct the physical denomination tally on a cash rollup
 * (void-PIN required). Once `adjusted_at` is set, RollUpBusDayCashCount
 * keeps the manual denominations / counted_total and only refreshes the
 * remittance and expense totals around them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bus_day_cash_counts', function (Blueprint $table) {
            $table->timestamp('adjusted_at')->nullable()->after('net_cash');
            $table->foreignId('adjusted_by')->nullable()->after('adjusted_at')->constrained('users')->nullOnDelete();
            $table->string('adjustment_reason', 255)->nullable()->after('adjusted_by');
        });
    }

    public function down(): void
    {
        Schema::table('bus_day_cash_counts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('adjusted_by');
            $table->dropColumn(['adjusted_at', 'adjustment_reason']);
        });
    }
};
