import { useCallback, useState } from 'react';
import { useNavigate } from 'react-router-dom';

import { useAuth } from '../../auth/AuthContext.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import { SkeletonTableRows } from '../../components/Skeleton.jsx';
import { charters } from '../../lib/api.js';
import { useCompanyPath } from '../../lib/companyScope.jsx';
import { LIST_PAGE_SIZE, useList } from '../../lib/useList.js';
import CharterFormModal from './CharterFormModal.jsx';
import { CHARTER_STATUSES, CharterStatus, charterStatusLabel, dt, peso } from './charterUi.jsx';

/**
 * Charter Bookings — Special trips (charter / hire): client, trip, crew and
 * agreed price; approval; and the charter's own money (see CharterDetail).
 * The conductor runs an Approved booking from the app as a Special trip.
 */
export default function Charters() {
    const { can } = useAuth();
    const navigate = useNavigate();
    const companyPath = useCompanyPath();
    const [filters, setFilters] = useState({ status: '', q: '', from: '', to: '' });
    const [creating, setCreating] = useState(false);

    const fetcher = useCallback((params) => charters.list({
        ...params,
        ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '')),
    }), [filters]);
    const { rows, meta, loading, page, setPage, reload } = useList(fetcher, { deps: [filters], perPage: LIST_PAGE_SIZE });

    const setFilter = (key) => (e) => setFilters({ ...filters, [key]: e.target.value });

    return (
        <>
            <PageHeader
                title="Charter Bookings"
                subtitle="Special trips — book a client, assign the crew, approve, and track the charter's own payments"
                actions={can('charters.create') && (
                    <button className="btn btn-primary" onClick={() => setCreating(true)}>
                        <i className="bi bi-plus-lg me-1" /> New booking
                    </button>
                )}
            />

            <div className="card border-0 shadow-sm mb-3">
                <div className="card-body row g-2 align-items-end">
                    <div className="col-md-4">
                        <label className="form-label small">Search</label>
                        <input className="form-control" placeholder="Client, reference or destination" value={filters.q} onChange={setFilter('q')} />
                    </div>
                    <div className="col-md-3">
                        <label className="form-label small">Status</label>
                        <select className="form-select" value={filters.status} onChange={setFilter('status')}>
                            <option value="">All</option>
                            {CHARTER_STATUSES.map((s) => <option key={s} value={s}>{charterStatusLabel(s)}</option>)}
                        </select>
                    </div>
                    <div className="col-md-2">
                        <label className="form-label small">Departing from</label>
                        <input type="date" className="form-control" value={filters.from} onChange={setFilter('from')} />
                    </div>
                    <div className="col-md-2">
                        <label className="form-label small">to</label>
                        <input type="date" className="form-control" value={filters.to} onChange={setFilter('to')} />
                    </div>
                </div>
            </div>

            <div className="card border-0 shadow-sm">
                <div className="table-responsive">
                    <table className="table table-hover align-middle mb-0">
                        <thead className="table-light">
                            <tr>
                                <th>Reference</th>
                                <th>Client</th>
                                <th>Trip</th>
                                <th>Departure</th>
                                <th>Crew</th>
                                <th className="text-end">Agreed price</th>
                                <th className="text-end">Balance</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading && <SkeletonTableRows columns={8} />}
                            {!loading && rows.length === 0 && (
                                <tr><td colSpan={8} className="text-center py-4 text-body-secondary">No charter bookings yet.</td></tr>
                            )}
                            {rows.map((c) => (
                                <tr key={c.id} className="tf-row-link" onClick={() => navigate(companyPath(`charters/${c.id}`))}>
                                    <td className="fw-semibold text-nowrap">{c.reference}</td>
                                    <td>
                                        <div className="fw-semibold">{c.client_name}</div>
                                        <div className="small text-body-secondary">{c.contact_number}</div>
                                    </td>
                                    <td className="small">{c.pickup_point} <i className="bi bi-arrow-right mx-1" />{c.destination}</td>
                                    <td className="small text-nowrap">{dt(c.departure_at)}</td>
                                    <td className="small">
                                        <div>{c.bus?.bus_number ?? '—'}</div>
                                        <div className="text-body-secondary">{c.driver?.name ?? '—'} · {c.conductor?.name ?? '—'}</div>
                                    </td>
                                    <td className="text-end text-nowrap">{peso(c.agreed_price)}</td>
                                    <td className={`text-end text-nowrap ${c.ledger?.balance > 0 ? 'text-danger fw-semibold' : ''}`}>
                                        {c.ledger ? peso(c.ledger.balance) : '—'}
                                    </td>
                                    <td><CharterStatus status={c.status} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <Pagination meta={meta} page={page} onPage={setPage} />

            <CharterFormModal
                open={creating}
                onClose={() => setCreating(false)}
                onSaved={(saved) => {
                    setCreating(false);
                    reload();
                    navigate(companyPath(`charters/${saved.id}`));
                }}
            />
        </>
    );
}
