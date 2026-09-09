<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispatches — migrated from BITS `dispatches` (docs/MIGRATION_MAP.md §2.3).
 * A barker (terminal dispatcher) payout recorded against a trip. The total
 * of a trip's dispatches is subtracted from the suggested remittance (§4.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();

            $table->string('barker_name', 150);
            $table->decimal('amount', 8, 2)->default(0);
            $table->timestamp('dispatched_at')->useCurrent();

            $table->timestamps();

            $table->index('trip_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatches');
    }
};
