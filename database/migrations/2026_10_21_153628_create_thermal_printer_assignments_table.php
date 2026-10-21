<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only history of a thermal printer's company / bus / status changes.
 * `thermal_printers` holds the current assignment; this keeps every earlier
 * one. Bus labels are snapshotted because a bus can be deleted later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thermal_printer_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thermal_printer_id')->constrained()->cascadeOnDelete();
            $table->string('action', 30); // registered | company_assigned | bus_assigned | bus_removed | status_changed | …

            $table->foreignId('from_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('to_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('from_bus_id')->nullable()->constrained('buses')->nullOnDelete();
            $table->foreignId('to_bus_id')->nullable()->constrained('buses')->nullOnDelete();
            $table->string('from_bus_label', 100)->nullable();
            $table->string('to_bus_label', 100)->nullable();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();

            $table->text('remarks')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['thermal_printer_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thermal_printer_assignments');
    }
};
