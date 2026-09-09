import { useCallback, useMemo, useState } from 'react';
import { swal as Swal } from '../../lib/ui.js';

import { useAuth } from '../../auth/AuthContext.jsx';
import DataTable from '../../components/DataTable.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import { attendance as attendanceApi } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';
import { notifyError, notifySuccess } from '../../lib/ui.js';

const dt = (v) => (v ? new Date(v).toLocaleString() : '—');
const hhmm = (mins) => {
    if (mins == null) return '—';
    const m = Math.max(0, Math.round(mins));
    return `${Math.floor(m / 60)}h ${m % 60}m`;
};

export default function Attendance() {
    const { can } = useAuth();
    const [filters, setFilters] = useState({ date: '', open: false });

    const fetcher = useCallback(
        (params) => attendanceApi.list({
            ...params,
            ...(filters.date ? { date: filters.date } : {}),
            ...(filters.open ? { open: 1 } : {}),
        }),
        [filters],
    );
    const { rows, meta, loading, page, setPage, reload } = useList(fetcher, { deps: [filters] });

    const forceClose = async (row) => {
        const { isConfirmed, value } = await Swal.fire({
            title: `Close ${row.user?.name}'s shift?`,
            html: `<div class="text-start small text-muted mb-2">Clocked in ${dt(row.clock_in_at)} — still open.</div>`,
            input: 'text', inputLabel: 'Note (required)', inputPlaceholder: 'e.g. forgot to clock out',
            showCancelButton: true, confirmButtonText: 'Force-close', customClass: { confirmButton: 'btn btn-danger px-4', cancelButton: 'btn btn-light px-4' },
            inputValidator: (v) => (!v ? 'A note is required' : undefined),
        });
        if (!isConfirmed) return;
        try {
            await attendanceApi.close(row.id, { note: value });
            notifySuccess('Shift closed.');
            reload();
        } catch (e) {
            notifyError(e);
        }
    };

    const columns = useMemo(() => [
        { key: 'user', header: 'Conductor', render: (r) => <span className="fw-semibold">{r.user?.name ?? '—'}</span> },
        { key: 'in', header: 'Clock in', render: (r) => <span className="small">{dt(r.clock_in_at)}<span className="text-muted"> · {r.clock_in_source}</span></span> },
        {
            key: 'out', header: 'Clock out',
            render: (r) => (r.clock_out_at
                ? <span className="small">{dt(r.clock_out_at)}<span className="text-muted"> · {r.clock_out_source}</span></span>
                : <span className="badge text-bg-success">on shift</span>),
        },
        { key: 'dur', header: 'Duration', render: (r) => hhmm(r.duration_minutes) },
        {
            key: 'closed', header: '',
            render: (r) => (r.closed_by
                ? <span className="badge text-bg-warning" title={r.closed_note}>force-closed · {r.closed_by}</span>
                : null),
        },
        {
            key: 'actions', header: '', className: 'text-end',
            render: (r) => (!r.clock_out_at && can('attendance.manage') && (
                <button className="btn btn-sm btn-outline-danger" onClick={() => forceClose(r)}>
                    <i className="bi bi-door-closed me-1" /> Force-close
                </button>
            )),
        },
    ], [can]);

    return (
        <>
            <PageHeader
                eyebrow="Operations"
                title="Attendance"
                subtitle="Conductor clock-in periods. A conductor must be on shift to start a trip."
            />

            <div className="d-flex flex-wrap gap-2 mb-3 align-items-end">
                <div>
                    <label className="form-label mb-1 small">Date</label>
                    <input type="date" className="form-control form-control-sm" value={filters.date}
                        onChange={(e) => setFilters((f) => ({ ...f, date: e.target.value }))} />
                </div>
                <div className="form-check ms-2">
                    <input id="open-only" type="checkbox" className="form-check-input" checked={filters.open}
                        onChange={(e) => setFilters((f) => ({ ...f, open: e.target.checked }))} />
                    <label htmlFor="open-only" className="form-check-label small">On-shift only</label>
                </div>
                {(filters.date || filters.open) && (
                    <button className="btn btn-sm btn-link" onClick={() => setFilters({ date: '', open: false })}>Clear</button>
                )}
            </div>

            <DataTable columns={columns} rows={rows} loading={loading} empty="No attendance records for this filter." />
            <Pagination meta={meta} page={page} onPage={setPage} />
        </>
    );
}
