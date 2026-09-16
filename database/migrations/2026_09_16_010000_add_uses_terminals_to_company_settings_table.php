<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company toggle for whether this transport company operates out of
 * physical terminals at all. `terminals.boarding_mode = 'Pickup'` already
 * means "no terminal boarding phase" for a single terminal (see
 * 2026_09_07_015630_create_terminals_table.php); this is the company-wide
 * version — when off, Start Trip skips terminal selection entirely and
 * `App\Actions\StartTrip` auto-provisions a Pickup-mode terminal per route
 * origin so the existing `Trip.origin` -> `Terminal` invariant still holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('uses_terminals')->default(true)->after('org_contact_number');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('uses_terminals');
        });
    }
};
