<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company roles (docs/MIGRATION_MAP.md §5). `roles` rows with
 * `company_id = null` are platform templates (Super Admin + the defaults
 * cloned into each new company). Every other row belongs to one company and
 * is fully editable by that company's admins. `is_admin` marks the single
 * company-admin-tier role (drives `UserRole::CompanyAdmin`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->boolean('is_admin')->default(false)->after('is_platform');
        });

        // key is unique per company, not globally.
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_key_unique');
            $table->unique(['company_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'key']);
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('is_admin');
            $table->unique('key');
        });
    }
};
