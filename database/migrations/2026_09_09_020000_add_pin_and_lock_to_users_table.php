<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conductor App PIN + account lock — BITS `users.pin` / `locked_at` /
 * `lock_type` / `locked_by` (docs/MIGRATION_MAP.md §2.1, `auth/accountLock.php`).
 * Two independent lock reasons share these columns: 'shift_end' (auto-set
 * when a conductor confirms their end-of-shift summary, auto-expires at the
 * next 3:59 AM — App\Support\AccountLock) and 'admin' (set/cleared by a
 * company admin, persists until explicitly unlocked).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pin_hash')->nullable()->after('sex');
            $table->timestamp('locked_at')->nullable()->after('pin_hash');
            $table->string('lock_type', 10)->nullable()->after('locked_at'); // shift_end | admin
            $table->foreignId('locked_by')->nullable()->after('lock_type')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('locked_by');
            $table->dropColumn(['pin_hash', 'locked_at', 'lock_type']);
        });
    }
};
