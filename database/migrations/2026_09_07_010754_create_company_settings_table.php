<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company branding, receipt and organization-identity settings.
 *
 * This is the multi-tenant split of BITS' single global `system_settings`
 * row (id = 1) — see docs/MIGRATION_MAP.md §2.5. One row per company,
 * created alongside the company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();

            // Branding (BITS system_settings.logo_path + colour palette).
            $table->string('logo_path')->nullable();
            $table->string('qr_payment_path')->nullable();
            $table->string('color_accent', 7)->default('#0d6efd');
            $table->string('color_accent_dark', 7)->default('#0b5ed7');

            // Receipt (BITS receipt_* columns).
            $table->decimal('receipt_width_mm', 4, 1)->default(54.0);
            $table->string('receipt_org_name', 150)->default('');
            $table->string('ticket_footer', 150)->default('Keep this ticket for your trip.');

            // Organization identity (BITS org_* / registration / accreditation).
            $table->string('registration_number', 100)->default('');
            $table->string('otc_accreditation_number', 100)->default('');
            $table->string('org_email', 150)->default('');
            $table->string('org_contact_number', 50)->default('');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
