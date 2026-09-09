<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preset "articles" for a Manual Amount passenger type — migrated from BITS
 * `passenger_type_articles` (docs/MIGRATION_MAP.md §2.2). When a Manual
 * Amount type has one or more Active articles, a ticket MUST name one and
 * its `amount` is used (never the client's typed amount).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passenger_type_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('passenger_type_id')->constrained()->cascadeOnDelete();

            $table->string('label', 100);
            $table->decimal('amount', 10, 2)->default(0);
            $table->integer('sort_order')->default(0);
            $table->string('status', 12)->default('Active'); // Active | Inactive

            $table->timestamps();

            $table->unique(['passenger_type_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passenger_type_articles');
    }
};
