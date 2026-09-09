<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fare matrix — migrated from BITS `fare_matrix` (docs/MIGRATION_MAP.md
 * §2.2 / §4.1). Exactly one row per route:
 *
 *   amount              base fare for a Regular passenger
 *   discounted_amount   optional manual override used ONLY for a passenger
 *                       type that actually gets a discount, for the rare
 *                       case where flat-percentage math doesn't match what
 *                       the old ticketing device charged
 *
 * `company_id` is carried here too (denormalised from the route) so the
 * CompanyScope constrains it directly without a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fare_matrix', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_id')->unique()->constrained()->cascadeOnDelete();

            $table->decimal('amount', 8, 2)->default(0);
            $table->decimal('discounted_amount', 8, 2)->nullable();
            $table->string('status', 12)->default('Active'); // Active | Inactive

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fare_matrix');
    }
};
