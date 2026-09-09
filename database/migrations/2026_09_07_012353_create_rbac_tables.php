<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role-based access control, carried over from BITS
 * (docs/MIGRATION_MAP.md §2.1 / §5):
 *
 *   permission_groups  — nav-menu grouping of permissions
 *   permissions        — one row per `permission_key` (e.g. "companies.view");
 *                        keeps BITS' nav_* metadata for building menus
 *   roles              — named bundles; `key` matches BITS role_name where
 *                        one exists (super_admin, admin/company_admin,
 *                        manager, chairman, conductor, office)
 *   role_permissions   — a role's default grants
 *   user_permissions   — the LIVE authorization source: per-user grants,
 *                        `allowed = 1` required. Seeded from the user's
 *                        role at creation, then editable per user — exactly
 *                        BITS' model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permission_group_id')->constrained()->cascadeOnDelete();
            $table->string('permission_key', 150)->unique();
            $table->string('name', 150);
            $table->string('description', 255)->nullable();
            $table->string('nav_label', 100)->nullable();
            $table->string('nav_url', 150)->nullable();
            $table->string('nav_icon', 50)->nullable();
            $table->unsignedInteger('nav_order')->default(0);
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            // Platform roles operate above any company (the Super Admin).
            $table->boolean('is_platform')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->boolean('allowed')->default(true);
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->boolean('allowed')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('permission_groups');
    }
};
