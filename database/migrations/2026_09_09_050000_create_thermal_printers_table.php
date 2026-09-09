<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thermal printers — BITS `thermal_printers` (docs/MIGRATION_MAP.md §2.2).
 * Assigned to a **conductor account**, not a bus (legacy `users.
 * thermal_printer_id`, unique — one printer per conductor, at most one
 * conductor per printer at a time; see `App\Models\ThermalPrinter::holder()`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thermal_printers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('device_id', 40);
            $table->string('mac_address', 20)->nullable();
            $table->string('model', 100);
            $table->string('status', 10)->default('Active'); // Active | Inactive

            $table->timestamps();

            $table->unique(['company_id', 'device_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('thermal_printer_id')->nullable()->after('pin_hash')
                ->constrained('thermal_printers')->nullOnDelete();
            $table->unique('thermal_printer_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['thermal_printer_id']);
            $table->dropConstrainedForeignId('thermal_printer_id');
        });

        Schema::dropIfExists('thermal_printers');
    }
};
