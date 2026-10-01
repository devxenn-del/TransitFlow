<?php

use App\Enums\BillingFrequency;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform fee catalogue — what companies pay TransitFlow (system,
 * rental, support … whatever the Super Admin defines). `amount` is the
 * STANDARD rate; a company's special rate lives in `company_fee_rates` and
 * never touches this row. Not company-owned.
 *
 * `applies_to_all_companies` = every company (including ones created
 * later) is billed this fee; otherwise only the companies in `company_fee`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fees', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('billing_frequency', 20)->default(BillingFrequency::Monthly->value);
            $table->unsignedSmallInteger('billing_interval_months')->nullable(); // only for `custom`
            $table->boolean('applies_to_all_companies')->default(true);
            $table->boolean('is_active')->default(true)->index();
            $table->date('effective_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fees');
    }
};
