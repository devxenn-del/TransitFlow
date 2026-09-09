<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A short-lived advisory lock so two people don't process the same trip's
 * remittance at once — BITS `src/RemittanceLock.php` (docs/MIGRATION_MAP.md
 * §4.3). The lock auto-expires (see App\Models\Trip::REMITTANCE_LOCK_MINUTES).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->foreignId('remittance_locked_by')->nullable()->after('remittance_flag_note')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('remittance_locked_at')->nullable()->after('remittance_locked_by');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('remittance_locked_by');
            $table->dropColumn('remittance_locked_at');
        });
    }
};
