<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned, platform-wide Privacy Policy / Terms of Use content — Super
 * Admin managed (see App\Support\LegalDocuments), never company-scoped
 * (no `company_id`, same as `system_settings`). Only one row per `type` is
 * `is_active` at a time; publishing a new version deactivates the previous
 * one but never deletes it, so historical acceptances stay meaningful.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30); // privacy_policy | terms_of_use
            $table->unsignedInteger('version');
            $table->string('title', 255);
            $table->longText('content');
            $table->date('effective_date');
            $table->boolean('is_active')->default(false);
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['type', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};
