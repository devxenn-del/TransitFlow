<?php

use App\Enums\BillingStatementStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly billing statements per company, and their line items. Each item
 * SNAPSHOTS the fee name, frequency and the amount actually charged, so
 * later fee or special-rate changes never rewrite past billing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 40)->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('issued_on');
            $table->date('due_on');
            $table->decimal('total', 12, 2);
            $table->string('status', 20)->default(BillingStatementStatus::Unpaid->value);
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'period_start']);
        });

        Schema::create('billing_statement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_statement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('fee_name', 120);
            $table->text('fee_description')->nullable();
            $table->string('billing_frequency', 20);
            $table->unsignedSmallInteger('billing_interval_months')->nullable();
            $table->string('pricing_type', 20);
            $table->decimal('standard_amount', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_statement_items');
        Schema::dropIfExists('billing_statements');
    }
};
