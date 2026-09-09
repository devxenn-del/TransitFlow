<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only ledger of every `system_settings` change — the "Configuration
 * History" a Super Admin can review and roll back from
 * (docs/PARITY_CHECKLIST.md §K). Rows are never updated or deleted; the
 * actor's name is snapshotted so the ledger stays readable even if the
 * account is later removed. Mirrors `cash_count_history`'s append-only
 * shape (App\Support\AccountLock's sibling for settings, not cash).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_setting_history', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key', 100);
            $table->text('previous_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('status', 20); // validated | failed | rolled_back
            $table->string('reason', 255)->nullable();

            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('changed_by_name', 150)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['setting_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_setting_history');
    }
};
