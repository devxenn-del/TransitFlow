<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The physical denomination count captured when a trip's remittance is
 * *received* (docs/MIGRATION_MAP.md §4.3–4.4). One row per trip. Each
 * received count rolls up into `bus_day_cash_counts` for that bus / date /
 * shift. Voiding a row (manager void-PIN) rolls it back out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remittance_cash_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->nullable()->constrained()->nullOnDelete();

            $table->date('op_date');
            $table->string('shift', 10);

            foreach (['q1000', 'q500', 'q200', 'q100', 'q50', 'q20', 'q10', 'q5', 'q1'] as $denomination) {
                $table->unsignedInteger($denomination)->default(0);
            }
            $table->unsignedBigInteger('counted_total')->default(0);
            $table->unsignedBigInteger('expected_amount')->default(0); // trips.remitted_amount at receive time
            $table->bigInteger('variance')->default(0);                // counted_total - expected_amount

            $table->string('status', 10)->default('Received'); // Received | Voided

            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('received_by_name', 150)->nullable();
            $table->timestamp('received_at')->useCurrent();

            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();

            $table->timestamps();

            $table->index('trip_id');
            $table->index(['company_id', 'bus_id', 'op_date', 'shift']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remittance_cash_counts');
    }
};
