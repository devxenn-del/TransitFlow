<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drivers — migrated from BITS `drivers` (docs/MIGRATION_MAP.md §2.1). A
 * login-free record picked per trip (unlike the bus, which is assigned to
 * the account). `employee_id` is auto-generated `E-YYMM-#######` on create
 * (App\Models\Driver::booted()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('name', 150);
            $table->string('employee_id', 20)->nullable();
            $table->string('license_number', 50)->nullable();
            $table->string('contact_number', 30)->nullable();
            $table->string('status', 12)->default('Active'); // Active | Inactive

            $table->timestamps();

            $table->unique(['company_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
