<?php

use App\Enums\PricingPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's pricing configuration — Standard or Special — set by the
 * Super Admin. Every existing company starts on Standard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('pricing_plan', 20)->default(PricingPlan::Standard->value)->after('can_create_accounts');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('pricing_plan');
        });
    }
};
