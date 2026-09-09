const MAP = {
    active: 'tf-badge-active',
    inactive: 'tf-badge-inactive',
    suspended: 'tf-badge-suspended',
    maintenance: 'tf-badge-maintenance',
};

export default function StatusBadge({ value }) {
    const cls = MAP[value] ?? 'tf-badge-inactive';
    return <span className={`badge ${cls} text-capitalize`}>{value}</span>;
}
