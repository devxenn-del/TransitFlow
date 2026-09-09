<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Route stops — migrated from BITS `route_stops` (docs/MIGRATION_MAP.md
 * §2.2). In BITS this was one global ordered list; here it is scoped to a
 * franchise, because the stop order is what lays out that franchise's fare
 * matrix grid (rows = origins, columns = destinations, in `sort_order`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();

            $table->string('name', 150);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 12)->default('Active'); // Active | Inactive

            $table->timestamps();

            $table->unique(['franchise_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_stops');
    }
};
