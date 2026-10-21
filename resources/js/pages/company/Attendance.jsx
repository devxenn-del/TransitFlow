import { useCallback, useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

import { useAuth } from '../../auth/AuthContext.jsx';
import DataTable from '../../components/DataTable.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import {
    attendance as attendanceApi,
    conductors as conductorsApi,
    driverAttendance as driverAttendanceApi,
    drivers as driversApi,
} from '../../lib/api.js';
import { LIST_PAGE_SIZE, useList } from '../../lib/useList.js';
import { notifyError, notifySuccess, swal as Swal } from '../../lib/ui.js';

const dt = (v) => (v ? new Date(v).toLocaleString() : '—');
const hhmm = (mins) => {
    if (mins == null) return '—';
    const m = Math.max(0, Math.round(mins));
    return `${Math.floor(m / 60)}h ${m % 60}m`;
};

/** Every filter, as kept in the URL (?tab=drivers&from=…) so a filtered view can be bookmarked or shared. */
const FILTER_KEYS = ['from', 'to', 'person', 'status', 'source', 'q'];
const STATUSES = [['', 'All'], ['on_shift', 'On shift'], ['clocked_out', 'Clocked out'], ['force_closed', 'Force-closed']];
const SOURCES = [['', 'All'], ['app', 'App'], ['web', 'Web']];

/**
 * Attendance — conductor shifts, and the drivers clocked in with them (a
 * driver is clocked in/out together with the conductor who verified them at
 * sign-in). Both tabs share one filter bar: date range, person, status,
 * source and a name search.
 */
export default function Attendance() {
    const { can } = useAuth();
    const [params, setParams] = useSearchParams();
    const tab = params.get('tab') === 'drivers' ? 'drivers' : 'conductors';
    const filters = useMemo(() => Object.fromEntries(FILTER_KEYS.map((k) => [k, params.get(k) ?? ''])), [params]);
    const [search, setSearch] = useState(filters.q);
    const [people, setPeople] = useState({ conductors: [], drivers: [] });

    // Who can be picked in the Person filter (best effort — the lists need their own view permission).
    useEffect(() => {
        conductorsApi.list({ per_page: 500 }).then((r) => setPeople((p) => ({ ...p, conductors: r.data ?? [] }))).catch(() => {});
        driversApi.list({ per_page: 500 }).then((r) => setPeople((p) => ({ ...p, drivers: r.data ?? [] }))).catch(() => {});
    }, []);

    const setFilter = (key, value) => {
        const next = new URLSearchParams(params);
        if (value) next.set(key, value); else next.delete(key);
        setParams(next, { replace: true });
    };

    // The search box applies after a short pause, not on every keystroke.
    useEffect(() => {
        if (search === filters.q) return undefined;
        const t = setTimeout(() => setFilter('q', search), 350);
        return () => clearTimeout(t);
    }, [search]); // eslint-disable-line react-hooks/exhaustive-deps

    const setTab = (value) => {
        const next = new URLSearchParams(params);
        next.set('tab', value);
        next.delete('person'); // a conductor id means nothing on the drivers tab, and vice versa
        setParams(next, { replace: true });
    };
    const clearFilters = () => {
        setSearch('');
        setParams(tab === 'drivers' ? { tab } : {}, { replace: true });
    };
    const hasFilters = FILTER_KEYS.some((k) => filters[k]);

    const fetcher = useCallback((p) => {
        const query = {
            ...p,
            ...(filters.from ? { from: filters.from } : {}),
            ...(filters.to ? { to: filters.to } : {}),
            ...(filters.status ? { status: filters.status } : {}),
            ...(filters.source ? { source: filters.source } : {}),
            ...(filters.q ? { q: filters.q } : {}),
        };
        if (tab === 'drivers') {
            if (filters.person) query.driver_id = filters.person;
            return driverAttendanceApi.list(query);
        }
        if (filters.person) query.conductor_id = filters.person;
        return attendanceApi.list(query);
    }, [tab, filters]);
    const { rows, meta, loading, page, setPage, reload } = useList(fetcher, { deps: [tab, filters], perPage: LIST_PAGE_SIZE });

    const forceClose = async (row) => {
        const who = tab === 'drivers' ? row.driver?.name : row.user?.name;
        const { isConfirmed, value } = await Swal.fire({
            title: `Close ${who}'s ${tab === 'drivers' ? 'driver period' : 'shift'}?`,
            html: `<div class="text-start small text-muted mb-2">Clocked in ${dt(row.clock_in_at)} — still open.${tab === 'conductors' ? ' The driver on this shift is clocked out too.' : ' The conductor stays on shift.'}</div>`,
            input: 'text', inputLabel: 'Note (required)', inputPlaceholder: tab === 'drivers' ? 'e.g. went home sick' : 'e.g. forgot to clock out',
            showCancelButton: true, confirmButtonText: 'Force-close', customClass: { confirmButton: 'btn btn-danger px-4', cancelButton: 'btn btn-light px-4' },
            inputValidator: (v) => (!v ? 'A note is required' : undefined),
        });
        if (!isConfirmed) return;
        try {
            await (tab === 'drivers' ? driverAttendanceApi : attendanceApi).close(row.id, { note: value });
            notifySuccess('Closed.');
            reload();
        } catch (e) {
            notifyError(e);
        }
    };

    const status = (r) => (r.clock_out_at
        ? (r.closed_by
            ? <span className="badge text-bg-warning" title={r.closed_note}>force-closed · {r.closed_by}</span>
            : <span className="badge text-bg-secondary">clocked out</span>)
        : <span className="badge text-bg-success">on shift</span>);
    const clockIn = (r) => <span className="small">{dt(r.clock_in_at)}<span className="text-muted"> · {r.clock_in_source}</span></span>;
    const clockOut = (r) => (r.clock_out_at ? <span className="small">{dt(r.clock_out_at)}<span className="text-muted"> · {r.clock_out_source}</span></span> : '—');
    const closeAction = (r) => (!r.clock_out_at && can('attendance.manage') && (
        <button className="btn btn-sm btn-outline-danger" onClick={() => forceClose(r)}>
            <i className="bi bi-door-closed me-1" /> Force-close
        </button>
    ));

    const columns = tab === 'drivers'
        ? [
            { key: 'driver', header: 'Driver', render: (r) => <><span className="fw-semibold">{r.driver?.name ?? '—'}</span> <code className="small ms-1">{r.driver?.driver_code}</code></> },
            { key: 'conductor', header: 'With conductor', render: (r) => r.conductor?.name ?? '—' },
            { key: 'in', header: 'Clock in', render: clockIn },
            { key: 'out', header: 'Clock out', render: clockOut },
            { key: 'dur', header: 'Duration', render: (r) => hhmm(r.duration_minutes) },
            { key: 'status', header: 'Status', render: status },
            { key: 'actions', header: '', className: 'text-end', render: closeAction },
        ]
        : [
            { key: 'user', header: 'Conductor', render: (r) => <span className="fw-semibold">{r.user?.name ?? '—'}</span> },
            {
                key: 'drivers', header: 'Driver(s)',
                render: (r) => (r.drivers?.length
                    ? <span className="small">{r.drivers.map((d) => d.name).join(' → ')}</span>
                    : <span className="text-muted small">—</span>),
            },
            { key: 'in', header: 'Clock in', render: clockIn },
            { key: 'out', header: 'Clock out', render: clockOut },
            { key: 'dur', header: 'Duration', render: (r) => hhmm(r.duration_minutes) },
            { key: 'status', header: 'Status', render: status },
            { key: 'actions', header: '', className: 'text-end', render: closeAction },
        ];

    const personOptions = tab === 'drivers' ? people.drivers : people.conductors;

    return (
        <>
            <PageHeader
                eyebrow="Operations"
                title="Attendance"
                subtitle="Conductor shifts and the drivers clocked in with them. A conductor must be on shift to start a trip."
            />

            <ul className="nav nav-tabs mb-3">
                <li className="nav-item">
                    <button type="button" className={`nav-link ${tab === 'conductors' ? 'active' : ''}`} onClick={() => setTab('conductors')}>
                        <i className="bi bi-person-vcard me-1" /> Conductors
                    </button>
                </li>
                <li className="nav-item">
                    <button type="button" className={`nav-link ${tab === 'drivers' ? 'active' : ''}`} onClick={() => setTab('drivers')}>
                        <i className="bi bi-person-badge me-1" /> Drivers
                    </button>
                </li>
            </ul>

            <div className="card border-0 shadow-sm mb-3">
                <div className="card-body row g-2 align-items-end">
                    <div className="col-6 col-md-2">
                        <label className="form-label mb-1 small">From</label>
                        <input type="date" className="form-control form-control-sm" value={filters.from} max={filters.to || undefined}
                            onChange={(e) => setFilter('from', e.target.value)} />
                    </div>
                    <div className="col-6 col-md-2">
                        <label className="form-label mb-1 small">To</label>
                        <input type="date" className="form-control form-control-sm" value={filters.to} min={filters.from || undefined}
                            onChange={(e) => setFilter('to', e.target.value)} />
                    </div>
                    <div className="col-md-2">
                        <label className="form-label mb-1 small">{tab === 'drivers' ? 'Driver' : 'Conductor'}</label>
                        <select className="form-select form-select-sm" value={filters.person} onChange={(e) => setFilter('person', e.target.value)}>
                            <option value="">All</option>
                            {personOptions.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                        </select>
                    </div>
                    <div className="col-6 col-md-2">
                        <label className="form-label mb-1 small">Status</label>
                        <select className="form-select form-select-sm" value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
                            {STATUSES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                        </select>
                    </div>
                    <div className="col-6 col-md-1">
                        <label className="form-label mb-1 small">Source</label>
                        <select className="form-select form-select-sm" value={filters.source} onChange={(e) => setFilter('source', e.target.value)}>
                            {SOURCES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                        </select>
                    </div>
                    <div className="col-md-3">
                        <label className="form-label mb-1 small">Search</label>
                        <div className="input-group input-group-sm">
                            <span className="input-group-text"><i className="bi bi-search" /></span>
                            <input className="form-control" placeholder={tab === 'drivers' ? 'Driver name or code' : 'Conductor name'}
                                value={search} onChange={(e) => setSearch(e.target.value)} />
                            {hasFilters && <button type="button" className="btn btn-outline-secondary" onClick={clearFilters}>Clear</button>}
                        </div>
                    </div>
                </div>
            </div>

            <DataTable columns={columns} rows={rows} loading={loading} empty="No attendance records for this filter." />
            <Pagination meta={meta} page={page} onPage={setPage} />
        </>
    );
}
