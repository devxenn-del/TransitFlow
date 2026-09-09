<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fuel purchases — migrated from BITS `fuel_records`
 * (docs/MIGRATION_MAP.md §2.3). `amount_paid` is derived server-side from
 * `liters * price_per_liter`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuel_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->constrained()->cascadeOnDelete();

            $table->string('fuel_type', 12); // Diesel | Gasoline
            $table->decimal('liters', 8, 2);
            $table->decimal('price_per_liter', 8, 2);
            $table->decimal('amount_paid', 10, 2);
            $table->unsignedInteger('odometer')->nullable();
            $table->string('station', 120)->nullable();
            $table->timestamp('fueled_at');
            $table->string('notes', 255)->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recorded_by_name', 150)->nullable();
            $table->string('recorded_by_role', 40)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'fueled_at']);
            $table->index(['bus_id', 'fueled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_records');
    }
};
