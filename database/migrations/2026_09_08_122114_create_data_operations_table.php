<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log for the per-company data tools — BITS `admin/backup.php` /
 * `admin/cleandata.php` (docs/MIGRATION_MAP.md §K). One row per export or
 * clean-data run, with a per-table row-count summary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // export | clean
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('performed_by_name', 150)->nullable();
            $table->json('summary')->nullable(); // { table => rowCount, ... }
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_operations');
    }
};
