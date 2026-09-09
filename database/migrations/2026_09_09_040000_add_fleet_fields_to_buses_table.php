<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remaining BITS bus fields — `capacity`, `model`, `vehicle_type`
 * (docs/MIGRATION_MAP.md §2.2). `vehicle_type` is the source of truth for
 * which Fuel & Energy workflow a bus uses (diesel/gasoline fuel records vs
 * EV charging sessions) — carried over for parity; §I's Fuel & Energy
 * module does not yet cross-validate against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buses', function (Blueprint $table) {
            $table->unsignedSmallInteger('capacity')->nullable()->after('plate_number');
            $table->string('model', 100)->nullable()->after('capacity');
            $table->string('vehicle_type', 10)->default('diesel')->after('model'); // electric | diesel | gasoline
        });
    }

    public function down(): void
    {
        Schema::table('buses', function (Blueprint $table) {
            $table->dropColumn(['capacity', 'model', 'vehicle_type']);
        });
    }
};
