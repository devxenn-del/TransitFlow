<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The APK is stored on disk under a fixed name (overwritten on every
 * publish) so its URL never changes across version updates — matching the
 * BITS `admin/mobileapp.php` convention. This column keeps the real
 * uploaded filename so the download can still be served under it via
 * `Content-Disposition` (see Api\Meta\ServerConfigController::downloadApk).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_app_settings', function (Blueprint $table) {
            $table->string('apk_original_name', 255)->nullable()->after('apk_path');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_app_settings', function (Blueprint $table) {
            $table->dropColumn('apk_original_name');
        });
    }
};
