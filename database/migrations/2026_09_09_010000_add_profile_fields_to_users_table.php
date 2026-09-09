<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User profile fields — BITS `users.employee_id` + `account_details`
 * (docs/MIGRATION_MAP.md §2.1, §4.5). `employee_id` is auto-generated
 * `E-YYMM-#######` on create (App\Models\User::booted()), unique per
 * company (mirrors `drivers.employee_id`). The name/contact fields absorb
 * BITS' separate `account_details` table — `name` stays the single
 * display name used everywhere; these are optional structured detail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_id', 20)->nullable()->after('status');
            $table->string('first_name', 100)->nullable()->after('employee_id');
            $table->string('middle_name', 100)->nullable()->after('first_name');
            $table->string('last_name', 100)->nullable()->after('middle_name');
            $table->string('phone', 30)->nullable()->after('last_name');
            $table->string('address', 255)->nullable()->after('phone');
            $table->string('sex', 10)->nullable()->after('address'); // Male | Female

            $table->unique(['company_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'employee_id']);
            $table->dropColumn(['employee_id', 'first_name', 'middle_name', 'last_name', 'phone', 'address', 'sex']);
        });
    }
};
