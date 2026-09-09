<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Human-readable trip reference — `T-MMDDYY-BUSNUMBER-XXXX-XXXX`
 * (docs/MIGRATION_MAP.md §4.2). Generated on creation by the Trip model.
 * Used on the Remittance desk, receipts and the conductor app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('reference', 60)->nullable()->unique()->after('bus_number');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropColumn('reference');
        });
    }
};
