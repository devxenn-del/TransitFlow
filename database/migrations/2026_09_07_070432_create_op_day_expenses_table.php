<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational expenses — migrated from BITS `op_day_expenses`
 * (docs/MIGRATION_MAP.md §2.4 / §4.4). Cash paid out of a bus's takings for
 * a given operating date / shift, with its own denomination breakdown.
 * Keyed by `(bus_id, op_date, shift)` — the same key as the
 * `bus_day_cash_counts` rollup, into which it is netted. `expenses.void`
 * (manager, void-PIN) reverses it and restores the cash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('op_day_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->constrained()->cascadeOnDelete();

            $table->date('op_date');
            $table->string('shift', 10);
            $table->string('category', 40)->default('Other');
            $table->string('description', 255);
            $table->unsignedBigInteger('amount')->default(0);

            foreach (['q1000', 'q500', 'q200', 'q100', 'q50', 'q20', 'q10', 'q5', 'q1'] as $denomination) {
                $table->unsignedInteger($denomination)->default(0);
            }

            $table->string('status', 10)->default('Active'); // Active | Voided

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recorded_by_name', 150)->nullable();
            $table->timestamp('recorded_at')->useCurrent();

            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'op_date', 'shift']);
            $table->index(['bus_id', 'op_date', 'shift']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('op_day_expenses');
    }
};
