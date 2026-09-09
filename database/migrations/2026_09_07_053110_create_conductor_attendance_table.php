<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conductor attendance — migrated from BITS `conductor_attendance`
 * (docs/MIGRATION_MAP.md §2.3 / §4.2). One row per clock-in; `clock_out_at`
 * null means the period is still open. A conductor must have an open period
 * to start a trip. Admins with `attendance.manage` can force-close a
 * forgotten clock-out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conductor_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->timestamp('clock_in_at')->useCurrent();
            $table->timestamp('clock_out_at')->nullable();
            $table->string('clock_in_source', 10)->default('web');  // web | app
            $table->string('clock_out_source', 10)->nullable();

            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('closed_note', 255)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'clock_out_at']);
            $table->index(['company_id', 'clock_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conductor_attendance');
    }
};
