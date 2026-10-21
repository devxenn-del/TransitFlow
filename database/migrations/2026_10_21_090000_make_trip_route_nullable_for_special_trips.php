<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Special trip (charter / hire) has no route: no terminal origin, no
 * coverage, no stops and no passenger ticketing — only the bus and the
 * driver verified at sign-in (App\Actions\StartTrip). Regular trips still
 * always fill these in; the Start Trip request requires them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('origin', 150)->nullable()->change();
            $table->string('coverage_origin', 150)->nullable()->change();
            $table->string('coverage_destination', 150)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('origin', 150)->nullable(false)->default('')->change();
            $table->string('coverage_origin', 150)->nullable(false)->default('')->change();
            $table->string('coverage_destination', 150)->nullable(false)->default('')->change();
        });
    }
};
