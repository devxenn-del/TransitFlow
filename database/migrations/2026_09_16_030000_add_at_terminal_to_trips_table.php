<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the conductor declared this trip as starting from a designated
 * terminal (Origin/Destination picked from Terminal records) or a plain
 * route stop (picked from the route's own stop list) — a per-trip UI mode,
 * recorded for the trip history / Trip Ready Details recap. Doesn't change
 * how App\Actions\StartTrip validates origin: that already tries a real
 * Terminal match first and falls back to auto-provisioning one, regardless
 * of this flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->boolean('at_terminal')->default(true)->after('trip_type');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('at_terminal');
        });
    }
};
