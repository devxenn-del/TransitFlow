<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company-specific (special) pricing for one fee — Super Admin only. Applies
 * only while the company is on the `special` pricing plan, the row is
 * active, and the billing date falls within starts_on..ends_on. Otherwise
 * the fee's standard amount applies (App\Support\Billing\FeePricing).
 * Several rows per company+fee may exist over time; the latest-starting
 * one in effect wins. `internal_notes` are never shown to the company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_fee_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('internal_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'fee_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_fee_rates');
    }
};
