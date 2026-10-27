<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conductor app Offline Mode (BITS parity) — what the server needs to accept
 * a trip that was run on the phone with no connection and replayed later:
 *
 * `trips.client_request_id` — the app's local "PT-…" trip id. A replayed
 * start whose first attempt already landed (only the response was lost)
 * returns that same trip instead of a second one.
 *
 * `dispatches.client_uuid` — the same idempotency tickets and ticket groups
 * already have, so a replayed dispatch is never recorded twice.
 *
 * `tickets.offline_sync` / `dispatches.offline_sync` — the row was sold
 * offline and synced later (its issued_at / dispatched_at is the phone's
 * time, not the sync time), for reports and audits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('client_request_id', 64)->nullable()->after('reference');
            $table->unique(['conductor_id', 'client_request_id']);
        });

        Schema::table('dispatches', function (Blueprint $table) {
            $table->string('client_uuid', 64)->nullable()->after('dispatched_at');
            $table->boolean('offline_sync')->default(false)->after('client_uuid');
            $table->index(['trip_id', 'client_uuid']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->boolean('offline_sync')->default(false)->after('client_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('offline_sync');
        });

        Schema::table('dispatches', function (Blueprint $table) {
            $table->dropIndex(['trip_id', 'client_uuid']);
            $table->dropColumn(['client_uuid', 'offline_sync']);
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->dropUnique(['conductor_id', 'client_request_id']);
            $table->dropColumn('client_request_id');
        });
    }
};
