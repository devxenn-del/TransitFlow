<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pairs a conductor account with the one driver whose Driver Code they must
 * supply at login (see AuthController::login()). Nullable — only conductor
 * accounts use this, and an unpaired conductor simply cannot sign in through
 * the conductor login flow until an admin assigns one (spec: existing
 * accounts must not be silently broken, but also must not bypass the new
 * requirement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('driver_id')->nullable()->after('role_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_id');
        });
    }
};
