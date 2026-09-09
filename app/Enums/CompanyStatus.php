<?php

namespace App\Enums;

/**
 * Lifecycle state of a transport company on the TransitFlow platform.
 *
 * Only the Super Admin changes this. `Active` is the sole state in which a
 * company's users may sign in and use the platform; `Inactive` and
 * `Suspended` both block access (they differ only in intent — a deliberate
 * pause vs. an enforcement action).
 */
enum CompanyStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';

    /**
     * Whether a company in this state may be accessed by its users.
     */
    public function grantsAccess(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
