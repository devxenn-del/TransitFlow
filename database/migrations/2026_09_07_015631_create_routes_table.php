<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Routes — migrated from BITS `routes` (docs/MIGRATION_MAP.md §2.2), now
 * company-owned AND franchise-scoped. `origin` / `destination` are stop
 * names; a route is one cell of its franchise's fare-matrix grid. It is
 * only usable for a trip / ticket when Active AND it has an Active
 * fare_matrix row (§4.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('franchise_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('name', 150);
            $table->string('origin', 150);
            $table->string('destination', 150);
            $table->string('status', 12)->default('Active'); // Active | Inactive

            $table->timestamps();

            // One row per origin→destination pair within a franchise.
            $table->unique(['franchise_id', 'origin', 'destination']);
            $table->index(['company_id', 'origin', 'destination']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routes');
    }
};
