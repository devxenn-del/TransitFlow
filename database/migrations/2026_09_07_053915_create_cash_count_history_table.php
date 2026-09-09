<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only ledger of every cash-count / expense event — BITS
 * `cash_count_history` (docs/MIGRATION_MAP.md §2.4). Rows are never
 * updated or deleted; actor names are snapshotted so the ledger stays
 * readable even if an account is later removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_count_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_count_id')->nullable()->constrained('bus_day_cash_counts')->nullOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bus_id')->nullable()->constrained()->nullOnDelete();

            $table->date('op_date')->nullable();
            $table->string('shift', 10)->nullable();
            $table->string('type', 20); // RemitReceived | RemitVoid | Expense | ExpenseVoid
            $table->string('ref_code', 40)->nullable();
            $table->string('description', 255)->nullable();
            $table->bigInteger('amount')->default(0); // signed: +cash in, -cash out

            foreach (['q1000', 'q500', 'q200', 'q100', 'q50', 'q20', 'q10', 'q5', 'q1'] as $denomination) {
                $table->integer($denomination)->default(0);
            }

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recorded_by_name', 150)->nullable();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('authorized_by_name', 150)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index('cash_count_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_count_history');
    }
};
