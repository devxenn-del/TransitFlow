<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties every user to a company and gives them a coarse role.
 *
 * `company_id` is nullable: a null company means a platform-level account
 * (the Super Admin). Everyone else belongs to exactly one company and can
 * only ever see that company's data — enforced by App\Models\Scopes\CompanyScope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('role', 30)->default(UserRole::CompanyUser->value)->after('password');
            $table->string('status', 20)->default('active')->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn(['company_id', 'role', 'status']);
        });
    }
};
