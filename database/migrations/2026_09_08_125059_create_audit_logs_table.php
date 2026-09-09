<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * General activity / audit trail — who did what, when — across sensitive
 * actions (settings, roles, permissions, remittances, company status, data
 * tools, mobile-app releases). BITS keeps scattered audit tables; TransitFlow
 * consolidates them here (docs/PARITY_CHECKLIST.md §L "Audit trails").
 *
 * `company_id` is null for a platform-level action (e.g. a Super Admin
 * creating a company). Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 150)->nullable();
            $table->string('action', 80);            // dotted, e.g. "company.settings.updated"
            $table->string('subject_type', 60)->nullable(); // short class basename
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 150)->nullable();
            $table->json('context')->nullable();     // changed fields / extra detail
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'action']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
