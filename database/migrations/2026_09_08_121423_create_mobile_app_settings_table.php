<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company mobile-app distribution + auto-update contract — BITS
 * `admin/mobileapp.php` / `api/meta/serverConfig.php` /
 * `api/meta/check_update.php` (docs/MIGRATION_MAP.md §K).
 *
 * The app is not built yet, but the API keeps the contract: on launch the
 * app fetches `/api/meta/server-config?company={code}` and
 * `/api/meta/check-update?...` to learn its base URL, whether an update is
 * available, and whether it is mandatory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_app_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();

            // Self-updating server base URL the app should use after first contact.
            $table->string('api_base_url', 255)->nullable();

            // Latest published build.
            $table->string('latest_version', 20)->nullable();      // e.g. "1.4.2"
            $table->unsignedInteger('latest_version_code')->default(0); // Android versionCode
            $table->string('minimum_version', 20)->nullable();     // hard floor — below this, force update
            $table->boolean('force_update')->default(false);       // force even at/above minimum
            $table->string('download_url', 255)->nullable();
            $table->string('apk_path', 255)->nullable();
            $table->text('release_notes')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_settings');
    }
};
