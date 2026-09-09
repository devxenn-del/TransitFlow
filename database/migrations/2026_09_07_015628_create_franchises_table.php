<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LTFRB franchises — migrated from BITS `franchises` (docs/MIGRATION_MAP.md
 * §2.5), now company-owned. In TransitFlow a franchise is also the scope of
 * a fare matrix: it owns an ordered list of route stops, and the routes
 * (origin→destination pairs) + their fares hang off it as an editable grid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franchises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('applicant_name', 150)->default('');
            $table->string('route_description', 255)->default('');
            $table->string('route_origin', 150)->default('');
            $table->string('route_destination', 150)->default('');
            $table->string('case_no', 100)->default('');
            $table->string('status', 12)->default('Active'); // Active | Inactive

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franchises');
    }
};
