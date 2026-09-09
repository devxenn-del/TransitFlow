<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registered conductor devices — BITS `admin/deviceMonitoring.php` /
 * `api/devices/register.php` (docs/MIGRATION_MAP.md §K). One row per physical
 * device; the mobile app upserts it on launch and periodically, which
 * doubles as a "last seen" heartbeat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_uuid', 191);
            $table->string('platform', 20)->default('android'); // android | ios | web
            $table->string('model', 120)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->string('push_token', 512)->nullable();
            $table->timestamp('registered_at')->useCurrent();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'device_uuid']);
            $table->index(['company_id', 'last_seen_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
