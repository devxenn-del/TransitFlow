<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regular vs Special (chartered/special-hire) trip classification —
 * conductor-declared at Start Trip, defaults to Regular for every
 * scheduled-route trip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('trip_type', 12)->default('Regular')->after('coverage_destination'); // Regular | Special
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('trip_type');
        });
    }
};
