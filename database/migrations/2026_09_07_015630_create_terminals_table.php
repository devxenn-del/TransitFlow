<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Terminals — migrated from BITS `terminals` (docs/MIGRATION_MAP.md §2.2),
 * now company-owned.
 *
 * `boarding_mode` decides how a trip that starts here is allowed to board:
 * a Pickup-only terminal (e.g. a garage) has no terminal boarding phase, so
 * a trip from it starts already On-Trip (enforced in the Trips module).
 * `default_route_origin` is the stop name the "To" picker reverse-maps to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('name', 150);
            $table->string('default_route_origin', 150)->nullable();
            $table->string('boarding_mode', 12)->default('Both'); // Both | Terminal | Pickup
            $table->string('status', 12)->default('Active');       // Active | Inactive

            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminals');
    }
};
