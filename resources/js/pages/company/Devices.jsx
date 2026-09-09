import { useCallback, useMemo, useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import DataTable from '../../components/DataTable.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import { swal as Swal } from '../../lib/ui.js';
import { devices } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';
import { notifyError, notifySuccess } from '../../lib/ui.js';

const dt = (v) => (v ? new Date(v).toLocaleString() : '—');

function ago(iso) {
    if (!iso) return 'never';
    const mins = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.round(mins / 60);
    if (hrs < 24) return `${hrs}h ago`;
    return `${Math.round(hrs / 24)}d ago`;
}

export default function Devices() {
    const { can } = useAuth();
    const canDelete = can('devices.delete');
    const [filters, setFilters] = useState({ platform: '', stale: false });

    const fetcher = useCallback(
        (params) => devices.list({
            ...params,
            ...(filters.platform ? { platform: filters.platform } : {}),
            ...(filters.stale ? { stale: 1 } : {}),
        }),
        [filters],
    );
    const { rows, meta, loading, page, setPage, reload } = useList(fetcher, { deps: [filters] });

    const deregister = async (row) => {
        const { isConfirmed } = await Swal.fire({
            title: 'Deregister device?',
            text: `${row.model ?? row.device_uuid} — the app will re-register on next launch.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Deregister',
            customClass: { confirmButton: 'btn btn-danger px-4', cancelButton: 'btn btn-light px-4' },
        });
        if (!isConfirmed) return;
        try {
            await devices.remove(row.id);
            notifySuccess('Device deregistered.');
            reload();
        } catch (e) {
            notifyError(e);
        }
    };

    const columns = useMemo(() => [
        {
            key: 'user', header: 'Conductor',
            render: (r) => <span className="fw-semibold">{r.user?.name ?? '—'}</span>,
        },
        { key: 'platform', header: 'Platform', render: (r) => <span className="text-capitalize">{r.platform}</span> },
        { key: 'model', header: 'Model', render: (r) => r.model ?? '—' },
        { key: 'app_version', header: 'App', render: (r) => r.app_version ?? '—' },
        {
            key: 'last_seen', header: 'Last seen',
            render: (r) => (
                <span title={dt(r.last_seen_at)}>
                    <span className={`badge me-2 ${r.online ? 'text-bg-success' : 'text-bg-secondary'}`}>
                        {r.online ? 'online' : 'offline'}
                    </span>
                    <span className="small text-muted">{ago(r.last_seen_at)}</span>
                </span>
            ),
        },
        { key: 'registered', header: 'Registered', className: 'small text-muted', render: (r) => dt(r.registered_at) },
        ...(canDelete ? [{
            key: 'actions', header: '', className: 'text-end',
            render: (r) => (
                <button className="btn btn-sm btn-outline-danger" onClick={() => deregister(r)}>
                    <i className="bi bi-trash" />
                </button>
            ),
        }] : []),
    ], [canDelete]);

    return (
        <>
            <PageHeader
                eyebrow="Company"
                title="Devices"
                subtitle="Conductor devices that have registered with the mobile app, with their last-seen heartbeat."
            />

            <div className="d-flex flex-wrap gap-2 mb-3 align-items-end">
                <div>
                    <label className="form-label mb-1 small">Platform</label>
                    <select className="form-select form-select-sm" value={filters.platform}
                        onChange={(e) => setFilters((f) => ({ ...f, platform: e.target.value }))}>
                        <option value="">All</option>
                        <option value="android">Android</option>
                        <option value="ios">iOS</option>
                        <option value="web">Web</option>
                    </select>
                </div>
                <div className="form-check mb-1">
                    <input className="form-check-input" type="checkbox" id="stale" checked={filters.stale}
                        onChange={(e) => setFilters((f) => ({ ...f, stale: e.target.checked }))} />
                    <label className="form-check-label small" htmlFor="stale">Offline only</label>
                </div>
            </div>

            <DataTable columns={columns} rows={rows} loading={loading} empty="No devices have registered yet." />
            <Pagination meta={meta} page={page} onPage={setPage} />
        </>
    );
}
