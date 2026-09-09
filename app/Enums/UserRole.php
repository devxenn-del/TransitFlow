<?php

namespace App\Enums;

use App\Models\Role;

/**
 * Coarse account type, used for platform-vs-company routing and as the
 * backstop for authorization.
 *
 * This is deliberately a small, fixed set. The fine-grained BITS-style
 * roles-and-permissions system (`super_admin`, `admin`, `manager`,
 * `chairman`, `conductor`, `office` plus per-permission-key grants) is
 * layered on top of this in Phase 4; until then this enum is the whole
 * authorization story.
 *
 * - `SuperAdmin`  — operates the TransitFlow platform itself; has no
 *                   `company_id`. Bypasses company scoping entirely.
 * - `CompanyAdmin` — administers exactly one company (their `company_id`).
 * - `CompanyUser`  — any non-admin account inside a company (staff, office,
 *                    conductor, …). Refined in Phase 4.
 */
enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case CompanyAdmin = 'company_admin';
    case CompanyUser = 'company_user';

    /**
     * True for roles that operate at the platform level rather than inside
     * a single company.
     */
    public function isPlatformLevel(): bool
    {
        return $this === self::SuperAdmin;
    }

    /**
     * Derive the coarse portal discriminator from a fine-grained
     * App\Models\Role. `company_admin` is the only fine role that maps to
     * the CompanyAdmin portal; every other company role is a CompanyUser.
     */
    public static function forRole(Role $role): self
    {
        return match (true) {
            $role->is_platform, $role->key === 'super_admin' => self::SuperAdmin,
            $role->is_admin, $role->key === 'company_admin' => self::CompanyAdmin,
            default => self::CompanyUser,
        };
    }

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->value();
    }
}
