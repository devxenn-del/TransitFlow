<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops Admin Bus Assignments, the Remittance desk, Cash Count (+ its
 * history/void-attempt ledgers) and Expenses, along with the manager
 * void-PIN columns those features were the only consumers of. Company
 * decision: these modules are being retired — Fleet keeps Drivers /
 * Conductors / Buses etc., Operations keeps Live Monitor, Trip Monitoring,
 * Fuel & Energy and Attendance.
 *
 * `cash_count_history` is dropped before `bus_day_cash_counts` because it
 * holds a foreign key to it. The now-retired permission catalogue rows are
 * deleted too (cascades into role_permissions/user_permissions/
 * company_permissions) — RbacSeeder only ever adds/updates, it never prunes
 * keys removed from its catalogue.
 */
return new class extends Migration
{
    private const RETIRED_PERMISSION_KEYS = [
        'adminassignments.view', 'adminassignments.create', 'adminassignments.edit', 'adminassignments.delete',
        'remittances.view', 'remittances.receive', 'remittances.void', 'remittances.approve',
        'cashcount.view', 'cashcount.adjust',
        'voidpin.manage', 'voidsecurity.view', 'voidsecurity.manage',
        'expenses.view', 'expenses.create', 'expenses.void',
        'dailyops.view', 'expensereport.view', 'cashcountreport.view',
    ];

    private const RETIRED_PERMISSION_GROUPS = [
        'Admin Bus Assignments', 'Remittances', 'Cash Count', 'Void Security', 'Expenses',
    ];

    public function up(): void
    {
        Schema::dropIfExists('cash_count_history');
        Schema::dropIfExists('cash_count_void_attempts');
        Schema::dropIfExists('remittance_cash_counts');
        Schema::dropIfExists('bus_day_cash_counts');
        Schema::dropIfExists('op_day_expenses');
        Schema::dropIfExists('admin_bus_assignments');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['void_pin_hash', 'void_pin_failed_count', 'void_pin_locked_until']);
        });

        DB::table('permissions')->whereIn('permission_key', self::RETIRED_PERMISSION_KEYS)->delete();
        DB::table('permission_groups')->whereIn('name', self::RETIRED_PERMISSION_GROUPS)->delete();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('void_pin_hash')->nullable()->after('status');
            $table->unsignedTinyInteger('void_pin_failed_count')->default(0)->after('void_pin_hash');
            $table->timestamp('void_pin_locked_until')->nullable()->after('void_pin_failed_count');
        });

        Schema::create('admin_bus_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('shift', 10)->default('Morning');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedTinyInteger('days_mask')->default(127);
            $table->string('status', 10)->default('Active');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'bus_id', 'status', 'effective_from'], 'idx_aba_bus');
            $table->index(['company_id', 'user_id', 'status', 'effective_from'], 'idx_aba_user');
        });

        Schema::create('op_day_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->constrained()->cascadeOnDelete();

            $table->date('op_date');
            $table->string('shift', 10);
            $table->string('category', 40)->default('Other');
            $table->string('description', 255);
            $table->unsignedBigInteger('amount')->default(0);

            foreach (['q1000', 'q500', 'q200', 'q100', 'q50', 'q20', 'q10', 'q5', 'q1'] as $denomination) {
                $table->unsignedInteger($denomination)->default(0);
            }

            $table->string('status', 10)->default('Active');

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recorded_by_name', 150)->nullable();
            $table->timestamp('recorded_at')->useCurrent();

            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'op_date', 'shift']);
            $table->index(['bus_id', 'op_date', 'shift']);
        });

        Schema::create('bus_day_cash_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->constrained()->cascadeOnDelete();

            $table->date('op_date');
            $table->string('shift', 10);

            $table->unsignedBigInteger('remitted_total')->default(0);
            $table->unsignedInteger('trip_count')->default(0);

            foreach (['q1000', 'q500', 'q200', 'q100', 'q50', 'q20', 'q10', 'q5', 'q1'] as $denomination) {
                $table->unsignedInteger($denomination)->default(0);
            }
            $table->unsignedBigInteger('counted_total')->default(0);
            $table->bigInteger('variance')->default(0);

            $table->string('status', 12)->default('Open');

            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('counted_by_name', 150)->nullable();
            $table->timestamp('counted_at')->nullable();

            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();

            $table->timestamps();

            $table->unique(['bus_id', 'op_date', 'shift']);
            $table->index(['company_id', 'op_date', 'shift']);
        });

        // Re-lands the two follow-up ALTERs originally made to bus_day_cash_counts.
        Schema::table('bus_day_cash_counts', function (Blueprint $table) {
            $table->unsignedBigInteger('expenses_total')->default(0)->after('counted_total');
            $table->unsignedBigInteger('expense_count')->default(0)->after('trip_count');
            $table->bigInteger('net_cash')->default(0)->after('expenses_total');
            $table->timestamp('adjusted_at')->nullable()->after('void_reason');
            $table->foreignId('adjusted_by')->nullable()->after('adjusted_at')->constrained('users')->nullOnDelete();
            $table->string('adjustment_reason', 255)->nullable()->after('adjusted_by');
        });

        Schema::create('remittance_cash_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->nullable()->constrained()->nullOnDelete();

            $table->date('op_date');
            $table->string('shift', 10);

            foreach (['q1000', 'q500', 'q200', 'q100', 'q50', 'q20', 'q10', 'q5', 'q1'] as $denomination) {
                $table->unsignedInteger($denomination)->default(0);
            }
            $table->unsignedBigInteger('counted_total')->default(0);
            $table->unsignedBigInteger('expected_amount')->default(0);
            $table->bigInteger('variance')->default(0);

            $table->string('status', 10)->default('Received');

            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('received_by_name', 150)->nullable();
            $table->timestamp('received_at')->useCurrent();

            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();

            $table->timestamps();

            $table->index('trip_id');
            $table->index(['company_id', 'bus_id', 'op_date', 'shift']);
        });

        Schema::create('cash_count_void_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('subject', 60)->nullable();
            $table->boolean('success')->default(false);
            $table->string('detail', 255)->nullable();

            $table->timestamp('attempted_at')->useCurrent();

            $table->index(['company_id', 'manager_id', 'attempted_at'], 'ccva_company_manager_time_idx');
        });

        Schema::create('cash_count_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_count_id')->nullable()->constrained('bus_day_cash_counts')->nullOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bus_id')->nullable()->constrained()->nullOnDelete();

            $table->date('op_date')->nullable();
            $table->string('shift', 10)->nullable();
            $table->string('type', 20);
            $table->string('ref_code', 40)->nullable();
            $table->string('description', 255)->nullable();
            $table->bigInteger('amount')->default(0);

            foreach (['q1000', 'q500', 'q200', 'q100', 'q50', 'q20', 'q10', 'q5', 'q1'] as $denomination) {
                $table->integer($denomination)->default(0);
            }

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recorded_by_name', 150)->nullable();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('authorized_by_name', 150)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index('cash_count_id');
        });
    }
};
