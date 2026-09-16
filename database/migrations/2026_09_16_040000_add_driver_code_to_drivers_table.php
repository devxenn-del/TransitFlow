<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Driver Code — a separate, driver-facing identifier used to authenticate a
 * conductor login (paired via users.driver_id, see
 * add_driver_id_to_users_table). Distinct from employee_id (an HR/admin
 * identifier, not meant to be shared as a credential). Auto-generated
 * `DR-####` on create (App\Models\Driver::booted()), unique per company —
 * same scoping convention as employee_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->string('driver_code', 20)->nullable()->after('employee_id');
            $table->unique(['company_id', 'driver_code']);
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'driver_code']);
            $table->dropColumn('driver_code');
        });
    }
};
