<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide key/value configuration — Super Admin only
 * (docs/PARITY_CHECKLIST.md §K, `system.configuration.*`). Starts with a
 * single key, `api_base_url`: the default API base URL TransitFlow's mobile
 * app is told to use (`/api/meta/server-config`) when a company hasn't set
 * its own override on `mobile_app_settings.api_base_url`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key', 100)->unique();
            $table->text('setting_value')->nullable();
            $table->string('description', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name', 150)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
