import { useCallback, useState } from 'react';

import DataTable from '../../components/DataTable.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import { auditLog } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';

const dt = (v) => (v ? new Date(v).toLocaleString() : '—');

function ActionBadge({ action }) {
    const danger = /void|delete|force_ended|status_changed|clean/.test(action);
    const warn = /feature_access|permissions|role\.updated/.test(action);
    const cls = danger ? 'text-bg-danger' : warn ? 'text-bg-warning' : 'text-bg-light';
    return <span className={`badge ${cls} font-monospace`}>{action}</span>;
}

export default function AuditLog() {
    const [filters, setFilters] = useState({ action: '', from: '', to: '' });

    const fetcher = useCallback(
        (params) => auditLog.list({
            ...params,
            ...(filters.action ? { action: filters.action } : {}),
            ...(filters.from ? { from: filters.from } : {}),
            ...(filters.to ? { to: filters.to } : {}),
        }),
        [filters],
    );
    const { rows, meta, loading, page, setPage } = useList(fetcher, { deps: [filters] });

    const columns = [
        { key: 'created_at', header: 'When', className: 'small text-nowrap', render: (r) => dt(r.created_at) },
        { key: 'action', header: 'Action', render: (r) => <ActionBadge action={r.action} /> },
        {
            key: 'subject', header: 'Subject',
            render: (r) => (r.subject_label ? <span className="small">{r.subject_label}</span>
                : r.subject_type ? <span className="small text-muted">{r.subject_type} #{r.subject_id}</span> : '—'),
        },
        { key: 'user', header: 'By', className: 'small', render: (r) => r.user_name ?? '—' },
        {
            key: 'context', header: 'Detail',
            render: (r) => (r.context ? <code className="small text-body-tertiary">{JSON.stringify(r.context)}</code> : '—'),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Company"
                title="Audit Log"
                subtitle="Who changed what — settings, roles, permissions, remittances, trips and data tools."
            />

            <div className="d-flex flex-wrap gap-2 mb-3 align-items-end">
                <div>
                    <label className="form-label mb-1 small">Action starts with</label>
                    <input className="form-control form-control-sm" placeholder="e.g. remittance." value={filters.action}
                        onChange={(e) => setFilters((f) => ({ ...f, action: e.target.value }))} />
                </div>
                <div>
                    <label className="form-label mb-1 small">From</label>
                    <input type="date" className="form-control form-control-sm" value={filters.from}
                        onChange={(e) => setFilters((f) => ({ ...f, from: e.target.value }))} />
                </div>
                <div>
                    <label className="form-label mb-1 small">To</label>
                    <input type="date" className="form-control form-control-sm" value={filters.to}
                        onChange={(e) => setFilters((f) => ({ ...f, to: e.target.value }))} />
                </div>
            </div>

            <DataTable columns={columns} rows={rows} loading={loading} empty="No activity recorded yet." />
            <Pagination meta={meta} page={page} onPage={setPage} />
        </>
    );
}
