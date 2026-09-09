<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tickets — migrated from BITS `tickets` (docs/MIGRATION_MAP.md §2.3 / §4.1).
 *
 * `fare` is always resolved server-side (never trusted from the client).
 * `client_uuid` makes issuance idempotent for the mobile app's offline
 * queue: re-submitting the same uuid returns the original ticket instead
 * of double-charging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('passenger_type_id')->constrained()->cascadeOnDelete();

            $table->string('boarding_type', 10)->default('Terminal'); // Terminal | Pickup
            $table->string('payment_method', 10)->default('Cash');    // Cash | E-Wallet | QR
            $table->string('qr_reference', 6)->nullable();
            $table->string('article_label', 100)->nullable();
            $table->decimal('fare', 8, 2)->default(0);

            $table->timestamp('issued_at')->useCurrent();
            $table->timestamp('refunded_at')->nullable();
            $table->string('client_uuid', 64)->nullable();

            $table->timestamps();

            $table->index('trip_id');
            $table->index(['trip_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
