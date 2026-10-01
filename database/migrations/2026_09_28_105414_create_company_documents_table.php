<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company's own document library — fare matrix scans, franchise papers,
 * registration documents, permits. The file itself lives on the private
 * `local` disk under `company-documents/{company_id}/` and is only ever
 * served through the authenticated, company-scoped download endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('name', 150);
            $table->string('category', 30); // fare_matrix | franchise | registration | permit | other
            $table->text('description')->nullable();
            $table->date('expires_at')->nullable();

            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0);

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_documents');
    }
};
