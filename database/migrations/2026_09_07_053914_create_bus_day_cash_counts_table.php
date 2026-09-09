<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash counts — migrated from BITS `bus_day_cash_counts`
 * (docs/MIGRATION_MAP.md §2.4 / §4.4).
 *
 * One row per `(bus_id, op_date, shift)`. The row is created and its
 * `remitted_total` accumulated automatically as the bus's trips are ended
 * and remitted — it is never entered by hand. A manager then records the
 * physical denomination count (`q1000..q1` / `counted_total`) to reconcile
 * against that expected figure; `variance = counted_total - remitted_total`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bus_day_cash_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->constrained()->cascadeOnDelete();

            $table->date('op_date');
            $table->string('shift', 10); // Morning | Evening

            // Expected cash — accumulated from trip remittances.
            $table->unsignedBigInteger('remitted_total')->default(0);
            $table->unsignedInteger('trip_count')->default(0);

            // Physical count — recorded by a manager to reconcile.
            foreach (['q1000', 'q500', 'q200', 'q100', 'q50', 'q20', 'q10', 'q5', 'q1'] as $denomination) {
                $table->unsignedInteger($denomination)->default(0);
            }
            $table->unsignedBigInteger('counted_total')->default(0);
            $table->bigInteger('variance')->default(0); // counted_total - remitted_total

            $table->string('status', 12)->default('Open'); // Open | Reconciled | Voided

            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('counted_by_name', 150)->nullable();
            $table->timestamp('counted_at')->nullable();

            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();

            $table->timestamps();

            $table->unique(['bus_id', 'op_date', 'shift']);
            $table->index(['company_id', 'op_date', 'shift']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bus_day_cash_counts');
    }
};
