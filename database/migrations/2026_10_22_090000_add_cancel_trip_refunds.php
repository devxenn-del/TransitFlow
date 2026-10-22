<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emergency Cancel Trip with BITS' partial refunds (App\Support\TripCancellation).
 *
 * `tickets.refund_amount` — what was handed back on this ticket. A full
 * refund also sets `refunded_at` (the ticket drops out of every total, as
 * before); a partial refund leaves `refunded_at` null and lowers `fare` to
 * what the passenger actually paid for the part they rode, so every
 * SUM(fare) stays the money kept. The original fare = fare + refund_amount.
 *
 * `trips.cancel_stop` — where the cancelled trip stopped (refunds are
 * measured to it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->decimal('refund_amount', 8, 2)->nullable()->after('refunded_at');
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->string('cancel_stop')->nullable()->after('cancellation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('refund_amount');
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('cancel_stop');
        });
    }
};
