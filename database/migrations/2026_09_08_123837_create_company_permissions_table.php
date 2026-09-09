<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company permission availability — the Super Admin controls which
 * permission keys a company is allowed to use at all. A Company Admin can
 * only assign what the Super Admin has made available here.
 *
 * This table holds only EXPLICIT overrides. The default for any
 * company-assignable key with no row is "available" (`enabled = true`), so
 * an empty table = today's behaviour, no disruption. The Super Admin adds a
 * row with `enabled = false` to switch a permission (or a whole feature
 * group) off for one company; a disabled key stops working for every user
 * of that company immediately, even if their `user_permissions` still grant
 * it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_permissions');
    }
};
