<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Driver clock-in periods. Drivers have no account — the conductor's login
 * verifies the driver (by driver code), so a driver is clocked in and out
 * together with that conductor's shift (App\Support\DriverAttendanceSync):
 * conductor clocks in → driver in; conductor clocks out / ends shift / office
 * force-closes → driver out; conductor re-signs in with another driver
 * mid-shift → driver swapped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conductor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conductor_attendance_id')->constrained('conductor_attendance')->cascadeOnDelete();
            $table->timestamp('clock_in_at')->useCurrent();
            $table->timestamp('clock_out_at')->nullable();
            $table->string('clock_in_source', 10)->default('app');  // app | web | auto
            $table->string('clock_out_source', 10)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('closed_note', 255)->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'clock_out_at']);
            $table->index(['company_id', 'clock_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_attendance');
    }
};
