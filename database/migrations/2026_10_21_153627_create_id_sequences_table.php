<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named, transactional counters for generated identifiers — one row per
 * sequence, incremented under a row lock (App\Support\IdSequence) so two
 * simultaneous requests can never take the same number. First user: the
 * thermal printer Thermal ID / Device ID pair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('id_sequences', function (Blueprint $table) {
            $table->string('name', 50)->primary();
            $table->unsignedInteger('last_value')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_sequences');
    }
};
