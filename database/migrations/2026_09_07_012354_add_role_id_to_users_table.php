<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `role_id` is the fine-grained BITS-style role. The coarse `role` string
 * added in Phase 3 stays: it is the portal/type discriminator
 * (super_admin / company_admin / company_user — "which side of the app"),
 * while `role_id` + `user_permissions` decide what the account can actually
 * do. Same split BITS runs: `role_name` for routing, `user_permissions` for
 * every real check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('role')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');
        });
    }
};
