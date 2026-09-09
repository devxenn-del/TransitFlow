<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passenger types — migrated from BITS `passenger_types`
 * (docs/MIGRATION_MAP.md §2.2 / §4.1).
 *
 *   fare_mode = 'Fare Matrix'   → fare from the route's fare_matrix row,
 *                                 minus `discount_percent` (or the route's
 *                                 discounted_amount override)
 *   fare_mode = 'Manual Amount' → not a real trip fare; the conductor picks
 *                                 an amount, constrained to a
 *                                 passenger_type_articles preset when any
 *                                 exist (e.g. "Articles Sales")
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passenger_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('name', 100);
            $table->string('fare_mode', 20)->default('Fare Matrix'); // Fare Matrix | Manual Amount
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->integer('sort_order')->default(0);
            $table->string('status', 12)->default('Active'); // Active | Inactive

            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passenger_types');
    }
};
