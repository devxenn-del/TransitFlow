<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One mobile app, one set of distribution settings — not per company.
 * Every company's conductor app is the same build; a company code only
 * identifies the caller, it never selects a different APK. No real data
 * exists yet (docs/MIGRATION_MAP.md §K — "the app is not built yet"), so
 * this collapses straight to a single platform-wide row.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('mobile_app_settings')->delete();

        Schema::table('mobile_app_settings', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropUnique(['company_id']);
            $table->dropColumn('company_id');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_app_settings', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->unique()->constrained()->cascadeOnDelete();
        });
    }
};
