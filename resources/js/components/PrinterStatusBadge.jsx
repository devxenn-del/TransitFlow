/**
 * A thermal printer's inventory status (App\Enums\ThermalPrinterStatus).
 * This is where the unit is in its lifecycle — never whether it is online.
 */
const MAP = {
    Available: { cls: 'tf-badge-info', icon: 'bi-box-seam' },
    Assigned: { cls: 'tf-badge-active', icon: 'bi-check2-circle' },
    Returned: { cls: 'tf-badge-inactive', icon: 'bi-arrow-return-left' },
    Defective: { cls: 'tf-badge-suspended', icon: 'bi-exclamation-triangle' },
    'Under Maintenance': { cls: 'tf-badge-maintenance', icon: 'bi-tools' },
    Retired: { cls: 'tf-badge-retired', icon: 'bi-archive' },
};

export const PRINTER_STATUSES = Object.keys(MAP);

export default function PrinterStatusBadge({ value }) {
    const { cls, icon } = MAP[value] ?? MAP.Returned;
    return (
        <span className={`badge ${cls}`}>
            <i className={`bi ${icon} me-1`} aria-hidden="true" />{value}
        </span>
    );
}
