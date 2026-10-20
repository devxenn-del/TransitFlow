<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which route (franchise) the conductor picked at Start Trip. The conductor
 * app loads that franchise's full stop list and fare matrix for the trip,
 * instead of guessing the franchise from the coverage origin/destination.
 * Nullable: trips started before this column fall back to that guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->foreignId('franchise_id')->nullable()->after('driver_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('franchise_id');
        });
    }
};
