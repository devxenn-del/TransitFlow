<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only record of a user accepting the currently-active Privacy
 * Policy / Terms of Use versions (mobile and web). Never overwritten — a
 * new acceptance is a new row, so the compliance trail of who accepted
 * which version and when is preserved. No `company_id`: consent is a
 * platform-wide, per-user legal record, not a company one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_document_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('privacy_policy_version')->nullable();
            $table->unsignedInteger('terms_version')->nullable();
            $table->timestamp('accepted_at');
            $table->string('application_version', 30)->nullable();
            $table->string('platform', 20)->nullable(); // android | web
            $table->timestamps();
            $table->index(['user_id', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_document_acceptances');
    }
};
