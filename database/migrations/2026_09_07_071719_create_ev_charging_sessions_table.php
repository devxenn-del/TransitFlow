<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EV charging sessions — migrated from BITS `ev_charging_sessions`
 * (docs/MIGRATION_MAP.md §2.3). One `Charging` session per bus at a time,
 * enforced by the unique `active_bus_id` (set to the bus while charging,
 * nulled on completion).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ev_charging_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->constrained()->cascadeOnDelete();

            $table->string('status', 12)->default('Charging'); // Charging | Completed
            $table->timestamp('started_at');
            $table->unsignedTinyInteger('battery_start_pct');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedTinyInteger('battery_end_pct')->nullable();
            $table->string('location', 120)->nullable();
            $table->string('notes', 255)->nullable();

            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('started_by_name', 150)->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ended_by_name', 150)->nullable();

            $table->unsignedBigInteger('active_bus_id')->nullable()->unique();

            $table->timestamps();

            $table->index(['company_id', 'started_at']);
            $table->index(['bus_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ev_charging_sessions');
    }
};
