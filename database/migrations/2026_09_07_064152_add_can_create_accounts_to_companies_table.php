<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-controlled capability: whether a company may create its own user
 * accounts. Only the Super Admin can change it (Company management screen).
 * When off, a company admin can still edit / deactivate existing accounts,
 * but new accounts for that company must be created by the Super Admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('can_create_accounts')->default(true)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('can_create_accounts');
        });
    }
};
