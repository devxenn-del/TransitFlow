<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buses — the first company-owned operational resource, migrated from BITS
 * `buses` (docs/MIGRATION_MAP.md §2.2).
 *
 * In Phase 3 this exists mainly as the reference implementation of the
 * `BelongsToCompany` isolation pattern and the fixture the isolation tests
 * exercise. The remaining BITS bus fields (capacity, model, vehicle_type,
 * thermal_printer_id, …) are added when the Fleet module is migrated in
 * Phase 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('bus_number', 50);
            $table->string('plate_number', 50);
            $table->string('status', 20)->default('Active');

            $table->timestamps();

            // Uniqueness is per-company, never global — two companies may
            // legitimately run a bus "001".
            $table->unique(['company_id', 'bus_number']);
            $table->unique(['company_id', 'plate_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buses');
    }
};
