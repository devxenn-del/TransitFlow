<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bus's regular (assigned) driver. Informational — a conductor still
 * verifies whichever driver they are actually working with at sign-in —
 * but it lets the office see who normally drives which bus. A driver is
 * assigned to at most one bus at a time (enforced in App\Models\Bus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buses', function (Blueprint $table) {
            $table->foreignId('driver_id')->nullable()->after('company_id')->constrained('drivers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('buses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_id');
        });
    }
};
