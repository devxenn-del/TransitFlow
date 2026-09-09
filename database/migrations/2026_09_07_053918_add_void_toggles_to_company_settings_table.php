<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company void-feature toggles — BITS `system_settings.void_feature_enabled`
 * / `void_pin_required` (docs/MIGRATION_MAP.md §2.5 / §4.4), now per company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('void_feature_enabled')->default(true)->after('org_contact_number');
            $table->boolean('void_pin_required')->default(true)->after('void_feature_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['void_feature_enabled', 'void_pin_required']);
        });
    }
};
