<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manager void-PIN — BITS `users.void_pin_hash` + lockout counters
 * (docs/MIGRATION_MAP.md §4.4). A manager sets their own PIN; it gates
 * cash-count voids. Wrong PINs increment `void_pin_failed_count` and can
 * set `void_pin_locked_until`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('void_pin_hash')->nullable()->after('status');
            $table->unsignedTinyInteger('void_pin_failed_count')->default(0)->after('void_pin_hash');
            $table->timestamp('void_pin_locked_until')->nullable()->after('void_pin_failed_count');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['void_pin_hash', 'void_pin_failed_count', 'void_pin_locked_until']);
        });
    }
};
