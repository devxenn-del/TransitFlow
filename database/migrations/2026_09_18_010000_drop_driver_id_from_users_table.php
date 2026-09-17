<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reverses 2026_09_16_050000_add_driver_id_to_users_table.php — a conductor
 * is not permanently paired with one driver (they may work with a
 * different bus/driver from one shift to the next), so login instead
 * verifies whichever Active driver's code the conductor supplies (any
 * driver in their own company — see AuthController::verifyDriverCode()),
 * and that choice is carried on the issued Sanctum token, not this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('driver_id')->nullable()->after('role_id')
                ->constrained()->nullOnDelete();
        });
    }
};
