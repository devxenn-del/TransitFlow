/**
 * Shared bits for the Charter Bookings pages (Charters.jsx, CharterDetail.jsx,
 * CharterFormModal.jsx).
 */

export const CHARTER_STATUSES = ['Pending', 'Approved', 'InProgress', 'Completed', 'Settled', 'Rejected', 'Cancelled'];

const STATUS_STYLE = {
    Pending: ['text-bg-warning', 'Pending approval'],
    Approved: ['text-bg-primary', 'Approved'],
    Rejected: ['text-bg-danger', 'Rejected'],
    InProgress: ['text-bg-info', 'In progress'],
    Completed: ['text-bg-success', 'Completed'],
    Settled: ['text-bg-dark', 'Settled'],
    Cancelled: ['text-bg-secondary', 'Cancelled'],
};

export function charterStatusLabel(status) {
    return STATUS_STYLE[status]?.[1] ?? status;
}

export function CharterStatus({ status }) {
    const [cls, label] = STATUS_STYLE[status] ?? ['text-bg-secondary', status];
    return <span className={`badge ${cls}`}>{label}</span>;
}

export function peso(n) {
    return `₱${Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export function dt(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}

/** ISO → the "YYYY-MM-DDTHH:mm" a datetime-local input wants, in local time. */
export function toLocalInput(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
