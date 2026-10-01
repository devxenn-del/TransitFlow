/** Shared formatting for the fee / billing pages. */

export const BILLING_FREQUENCIES = [
    ['monthly', 'Monthly'],
    ['quarterly', 'Quarterly'],
    ['semi_annual', 'Semi-annual'],
    ['yearly', 'Yearly'],
    ['one_time', 'One-time'],
    ['custom', 'Custom interval'],
];

export const peso = (n) => `₱${Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export const isFree = (n) => Number(n ?? 0) === 0;

/** A fee rate: "Free" when it is ₱0, otherwise pesos. (Totals use peso().) */
export const feeAmount = (n) => (isFree(n) ? 'Free' : peso(n));

/** A `YYYY-MM-DD` date as e.g. "Mar 5, 2026" (parsed as a local date, not UTC). */
export const fmtDate = (v) => (v ? new Date(`${v}T00:00:00`).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '—');

/** Today as `YYYY-MM-DD` in local time. */
export const todayIso = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

/** Statement amount as printed on a bill: "PHP2,535.50". */
export const php = (n) => {
    const value = Number(n ?? 0);
    const formatted = Math.abs(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return `${value < 0 ? '-' : ''}PHP${formatted}`;
};

/** A `YYYY-MM-DD` date as "MM/DD/YYYY". */
export const mdy = (v) => {
    if (!v) return '—';
    const [y, m, d] = v.slice(0, 10).split('-');
    return `${m}/${d}/${y}`;
};

/** A timestamp as "MM/DD/YYYY hh:mm AM" in Philippine time (the app's timezone). */
export const mdyTime = (v) => {
    if (!v) return '—';
    return new Date(v).toLocaleString('en-US', {
        timeZone: 'Asia/Manila',
        month: '2-digit',
        day: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
    }).replace(',', '');
};

/** "07/24/2026-08/23/2026" */
export const billingPeriod = (s) => `${mdy(s.period_start)}-${mdy(s.period_end)}`;

/**
 * A statement's header exactly as on the company's bill:
 * company code, billing period, billing number, amount to pay, due date.
 */
export function StatementSummary({ statement, companyCode }) {
    const rows = [
        ['Company Code', statement.company_code ?? companyCode],
        ['Billing Period', billingPeriod(statement)],
        ['Billing Number', statement.billing_number],
        ['Amount to Pay', php(statement.total)],
        ['Due Date', mdy(statement.due_on)],
        ...(statement.status === 'paid' && statement.paid_at ? [['Payment made last', mdyTime(statement.paid_at)]] : []),
    ];
    return (
        <dl className="tf-statement-summary mb-0">
            {rows.map(([label, value]) => (
                <div key={label} className="row g-0 py-1">
                    <dt className="col-5 col-sm-4 fw-normal text-body-secondary">{label}:</dt>
                    <dd className={`col-7 col-sm-8 mb-0 ${label === 'Amount to Pay' ? 'fw-bold fs-5' : 'fw-semibold'}`}>{value}</dd>
                </div>
            ))}
        </dl>
    );
}

export function RateBadge({ type, label }) {
    return (
        <span className={`badge ${type === 'special' ? 'text-bg-warning-subtle text-warning-emphasis' : 'text-bg-light border'}`}>{label}</span>
    );
}
