<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nets operational expenses into the per bus / day / shift rollup:
 *   net_cash = counted_total - expenses_total
 * Maintained by App\Actions\RollUpBusDayCashCount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bus_day_cash_counts', function (Blueprint $table) {
            $table->unsignedBigInteger('expenses_total')->default(0)->after('counted_total');
            $table->bigInteger('net_cash')->default(0)->after('expenses_total');
            $table->unsignedInteger('expense_count')->default(0)->after('trip_count');
        });
    }

    public function down(): void
    {
        Schema::table('bus_day_cash_counts', function (Blueprint $table) {
            $table->dropColumn(['expenses_total', 'net_cash', 'expense_count']);
        });
    }
};
