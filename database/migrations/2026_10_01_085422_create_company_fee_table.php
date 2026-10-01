<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which companies a fee is assigned to when the fee is NOT applied to every
 * company (`fees.applies_to_all_companies = false`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_fee', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'fee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_fee');
    }
};
