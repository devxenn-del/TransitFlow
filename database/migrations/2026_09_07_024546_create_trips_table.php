<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trips — migrated from BITS `trips` (docs/MIGRATION_MAP.md §2.3 / §4.2).
 *
 * Status machine: Departure → OnTrip → Arrived (+ Cancelled from either
 * live state). A trip that starts from a Pickup-only terminal skips
 * Departure and begins already OnTrip.
 *
 * `company_id` is denormalised from the conductor so CompanyScope
 * constrains it directly. `coverage_*` is the origin→destination the trip
 * is declared to cover (used as the fare-lookup scope and on the
 * remittance report).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conductor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('bus_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();

            $table->string('bus_number', 50)->nullable();
            $table->string('origin', 150);
            $table->string('coverage_origin', 150);
            $table->string('coverage_destination', 150);

            $table->string('status', 12)->default('Departure'); // Departure | OnTrip | Arrived | Cancelled

            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('marked_on_trip_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->foreignId('force_ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('force_ended_at')->nullable();
            $table->string('force_ended_reason', 500)->nullable();

            // Remittance (§4.3) — reconciliation happens after the trip ends.
            $table->decimal('remitted_amount', 10, 2)->nullable();
            $table->timestamp('remittance_approved_at')->nullable();
            $table->foreignId('remittance_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('remittance_excess_amount', 10, 2)->nullable();
            $table->decimal('remittance_short_amount', 10, 2)->nullable();
            $table->boolean('remittance_flagged')->default(false);
            $table->string('remittance_flag_note', 255)->nullable();
            $table->string('remittance_note', 255)->nullable();
            $table->timestamp('remittance_received_at')->nullable();
            $table->foreignId('remittance_received_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['conductor_id', 'status']);
            $table->index(['bus_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
