<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conductor ⇄ bus assignment — migrated from BITS `conductor_buses`
 * (docs/MIGRATION_MAP.md §2.2). A conductor may be assigned several buses;
 * they pick which one when starting a trip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bus_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'bus_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bus_user');
    }
};
