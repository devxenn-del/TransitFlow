<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company report-PDF page setup — BITS `pdf_settings`
 * (docs/MIGRATION_MAP.md §K, open decision #1 resolved: per-company). Applied
 * by `App\Http\Controllers\Api\Company\ReportController` when it renders a
 * report through dompdf.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('pdf_paper_size', 12)->default('a4')->after('void_pin_required');       // a4 | letter | legal
            $table->string('pdf_orientation', 10)->default('landscape')->after('pdf_paper_size');  // portrait | landscape
            $table->unsignedSmallInteger('pdf_margin_mm')->default(10)->after('pdf_orientation');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['pdf_paper_size', 'pdf_orientation', 'pdf_margin_mm']);
        });
    }
};
