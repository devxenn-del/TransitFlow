<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only GPS breadcrumb log for the live fleet map — BITS
 * `bus_locations` / `api/trips/updateLocation.php` (docs/MIGRATION_MAP.md §H).
 *
 * One row per position report from a conductor's device while they have a
 * live trip on that bus. The live board reads the latest row per bus and
 * flags it stale after ~2 minutes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bus_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->constrained()->cascadeOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('speed_kph', 5, 1)->nullable();
            $table->unsignedSmallInteger('heading')->nullable(); // 0–359 degrees
            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'recorded_at']);
            $table->index(['bus_id', 'recorded_at']);
            $table->index('trip_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bus_locations');
    }
};
