<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Office-admin ⇄ bus, time-boxed & shift-aware — BITS `admin_bus_assignments`
 * (docs/MIGRATION_MAP.md §2.2, §4.4). Which non-conductor account is
 * responsible for a bus, over an effective-dated window, per shift
 * (Morning/Evening — a bus may have a different holder per shift on the
 * same dates). `days_mask`/`start_time`/`end_time` are carried for parity
 * with the legacy schema but not yet surfaced in the UI (legacy's own save
 * flow left them at their defaults too — see App\Models\AdminBusAssignment).
 *
 * Report/remittance scoping by this table (BITS `App\AdminBusAccess`) is a
 * deliberately deferred follow-up — see docs/PARITY_CHECKLIST.md §J. This
 * migration only lands the data model + management CRUD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_bus_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('shift', 10)->default('Morning'); // Morning | Evening
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedTinyInteger('days_mask')->default(127); // bit 0=Mon .. 6=Sun, 127 = every day
            $table->string('status', 10)->default('Active'); // Active | Inactive
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'bus_id', 'status', 'effective_from'], 'idx_aba_bus');
            $table->index(['company_id', 'user_id', 'status', 'effective_from'], 'idx_aba_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_bus_assignments');
    }
};
