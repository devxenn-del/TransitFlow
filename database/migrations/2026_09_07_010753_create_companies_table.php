<?php

use App\Enums\CompanyStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transport companies (tenants) on the TransitFlow platform. Every
 * company-owned record in the system ultimately belongs to one row here.
 *
 * BITS had no equivalent — it was a single-tenant system. See
 * docs/MIGRATION_MAP.md §0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 32)->unique();
            $table->string('slug')->unique();

            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();

            $table->string('address_line')->nullable();
            $table->string('address_barangay', 120)->nullable();
            $table->string('address_city', 120)->nullable();
            $table->string('address_province', 120)->nullable();

            $table->string('logo_path')->nullable();

            $table->string('status', 20)->default(CompanyStatus::Active->value)->index();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
