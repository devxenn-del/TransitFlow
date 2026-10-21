<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Charter bookings — the Special-trip (charter / hire) workflow: the office
 * books a client, assigns a bus / driver / conductor, approves it, the
 * conductor runs it as a Special trip on the app, and its money (agreed
 * price, deposit, payments, extra charges, actual expenses, settlement) is
 * kept here — never in ticket sales or passenger remittances.
 * See App\Models\CharterBooking and App\Support\CharterLedger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charter_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 20)->nullable()->index(); // CHR-000123, set after insert

            $table->string('client_name', 150);
            $table->string('contact_person', 150)->nullable();
            $table->string('contact_number', 50);
            $table->string('contact_email', 150)->nullable();
            $table->string('purpose', 255)->nullable();
            $table->string('pickup_point', 255);
            $table->string('destination', 255);
            $table->dateTime('departure_at');
            $table->dateTime('return_at'); // estimated — what conflict checks use
            $table->unsignedInteger('passenger_count')->nullable();
            $table->decimal('agreed_price', 12, 2);
            $table->text('notes')->nullable();

            $table->foreignId('bus_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->constrained()->restrictOnDelete();
            $table->foreignId('conductor_id')->constrained('users')->restrictOnDelete();

            // Pending | Approved | Rejected | InProgress | Completed | Settled | Cancelled
            $table->string('status', 20)->default('Pending')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejected_reason', 255)->nullable();
            $table->string('cancelled_reason', 255)->nullable();
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'departure_at']);
        });

        Schema::create('charter_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charter_booking_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10); // Deposit | Payment | Refund
            $table->decimal('amount', 12, 2);
            $table->string('method', 20)->default('Cash'); // Cash | Bank | E-Wallet | Other
            $table->string('reference_no', 100)->nullable();
            $table->dateTime('paid_at');
            $table->string('notes', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('charter_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charter_booking_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // Charge (billed to the client) | Expense (the company's cost)
            $table->string('category', 50);
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 12, 2);
            $table->dateTime('incurred_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->foreignId('charter_booking_id')->nullable()->after('franchise_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('charter_booking_id');
        });
        Schema::dropIfExists('charter_charges');
        Schema::dropIfExists('charter_payments');
        Schema::dropIfExists('charter_bookings');
    }
};
