<?php

namespace App\Enums;

/**
 * Inventory state of a platform-issued thermal printer — where the physical
 * unit is in its lifecycle, NOT whether it is connected (the web app cannot
 * see a Bluetooth printer; connectivity is never inferred from this).
 *
 * Invariants (enforced by the App\Actions\*ThermalPrinter* actions):
 * - Available / Returned / Retired → no company, no bus.
 * - Assigned → has a company; a bus is optional.
 * - Defective / Under Maintenance → may keep its company, never a bus.
 */
enum ThermalPrinterStatus: string
{
    case Available = 'Available';
    case Assigned = 'Assigned';
    case Returned = 'Returned';
    case Defective = 'Defective';
    case UnderMaintenance = 'Under Maintenance';
    case Retired = 'Retired';

    /**
     * The statuses an explicit status change may move to from this one.
     * `Assigned` is only reachable here as "back in service" for a printer
     * that still has its company — issuing a printer goes through company
     * assignment instead.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Available => [self::Defective, self::UnderMaintenance, self::Retired],
            self::Assigned => [self::Returned, self::Defective, self::UnderMaintenance],
            self::Returned => [self::Available, self::Defective, self::UnderMaintenance, self::Retired],
            self::Defective => [self::Available, self::Assigned, self::Returned, self::UnderMaintenance, self::Retired],
            self::UnderMaintenance => [self::Available, self::Assigned, self::Returned, self::Defective, self::Retired],
            self::Retired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Whether a printer in this state may be issued to a company. */
    public function acceptsCompany(): bool
    {
        return in_array($this, [self::Available, self::Returned, self::Assigned], true);
    }

    /** Whether a printer in this state may be put on (or kept on) a bus. */
    public function acceptsBus(): bool
    {
        return $this === self::Assigned;
    }

    /** Whether a printer in this state keeps its company link. */
    public function keepsCompany(): bool
    {
        return in_array($this, [self::Assigned, self::Defective, self::UnderMaintenance], true);
    }
}
