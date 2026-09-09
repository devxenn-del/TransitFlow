<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manager void-PIN audit trail — BITS `cash_count_void_attempts`
 * (docs/MIGRATION_MAP.md §4.4). One row per PIN check, success or failure,
 * so repeated failed attempts are visible on the Void Security console.
 * `subject` names what was being voided, e.g. "remittance:12".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_count_void_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('subject', 60)->nullable();
            $table->boolean('success')->default(false);
            $table->string('detail', 255)->nullable();

            $table->timestamp('attempted_at')->useCurrent();

            $table->index(['company_id', 'manager_id', 'attempted_at'], 'ccva_company_manager_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_count_void_attempts');
    }
};
