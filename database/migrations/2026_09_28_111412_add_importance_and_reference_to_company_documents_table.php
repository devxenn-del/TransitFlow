<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the company document library a general-purpose one: an
 * "important" flag (starred documents float to the top and have their own
 * filter), plus the reference number and issue date printed on most
 * official papers (permits, OR/CR, certificates).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_documents', function (Blueprint $table) {
            $table->boolean('is_important')->default(false)->after('description');
            $table->string('reference_number', 100)->nullable()->after('is_important');
            $table->date('issued_at')->nullable()->after('reference_number');

            $table->index(['company_id', 'is_important']);
            $table->index(['company_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('company_documents', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'is_important']);
            $table->dropIndex(['company_id', 'expires_at']);
            $table->dropColumn(['is_important', 'reference_number', 'issued_at']);
        });
    }
};
