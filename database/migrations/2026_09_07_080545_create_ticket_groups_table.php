<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group tickets — migrated from BITS `ticket_groups`
 * (docs/MIGRATION_MAP.md §4.1). One printed ticket / one payment for a
 * party boarding together (≤30 passenger lines). Each passenger still gets
 * its own `tickets` row (linked by `ticket_group_id`) so every report and
 * remittance query stays per-row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();

            $table->string('boarding_type', 10)->default('Terminal');
            $table->string('payment_method', 10)->default('Cash');
            $table->string('qr_reference', 6)->nullable();

            $table->unsignedSmallInteger('line_count')->default(0);
            $table->unsignedSmallInteger('passenger_count')->default(0);
            $table->decimal('total_fare', 10, 2)->default(0);

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->useCurrent();
            $table->string('client_uuid', 64)->nullable();

            $table->timestamps();

            $table->index('trip_id');
            $table->index(['trip_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_groups');
    }
};
