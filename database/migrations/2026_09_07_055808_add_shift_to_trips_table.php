<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A trip's operating date and shift, stamped at start (docs/MIGRATION_MAP.md
 * §4.4). Shift = `Evening` when the trip starts at or after 17:00, else
 * `Morning` — the key a received remittance rolls up under.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->date('op_date')->nullable()->after('coverage_destination');
            $table->string('shift', 10)->nullable()->after('op_date');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['op_date', 'shift']);
        });
    }
};
