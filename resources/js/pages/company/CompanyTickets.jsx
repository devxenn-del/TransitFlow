import { useEffect, useState } from 'react';

import DataTable from '../../components/DataTable.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import { companyTickets } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';

const PAYMENT_METHODS = ['Cash', 'E-Wallet', 'QR'];
const peso = (n) => `₱${Number(n ?? 0).toFixed(2)}`;
const when = (v) => (v ? new Date(v).toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—');

/** Every ticket the company has issued, newest first, across all trips. */
export default function CompanyTickets() {
    const [search, setSearch] = useState('');
    const [filters, setFilters] = useState({ q: '', from: '', to: '', payment_method: '' });
    const { rows, meta, loading, page, setPage } = useList(
        (params) => companyTickets.list({
            ...params,
            ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v)),
        }),
        { deps: [filters], perPage: 25 },
    );

    useEffect(() => {
        const t = setTimeout(() => {
            setFilters((f) => (f.q === search.trim() ? f : { ...f, q: search.trim() }));
            setPage(1);
        }, 300);
        return () => clearTimeout(t);
    }, [search, setPage]);

    const setFilter = (key, value) => { setFilters((f) => ({ ...f, [key]: value })); setPage(1); };

    const columns = [
        { key: 'issued_at', header: 'Issued', className: 'small text-nowrap', render: (t) => when(t.issued_at) },
        {
            key: 'trip',
            header: 'Trip',
            render: (t) => (
                <>
                    <div className="fw-semibold">{t.trip?.bus_number ?? '—'}</div>
                    <code className="small">{t.trip?.reference ?? `#${t.trip_id}`}</code>
                </>
            ),
        },
        { key: 'conductor', header: 'Conductor', className: 'small', render: (t) => t.trip?.conductor ?? '—' },
        { key: 'route', header: 'Route', className: 'small', render: (t) => (t.route ? `${t.route.origin} → ${t.route.destination}` : t.article_label ?? '—') },
        { key: 'passenger_type', header: 'Passenger', className: 'small', render: (t) => t.passenger_type ?? '—' },
        { key: 'payment_method', header: 'Payment', className: 'small', render: (t) => t.payment_method },
        {
            key: 'fare',
            header: 'Fare',
            className: 'text-end text-nowrap',
            render: (t) => (t.refunded_at
                ? <span className="text-decoration-line-through text-body-secondary" title="Refunded">{peso(t.fare)}</span>
                : <span className="fw-semibold">{peso(t.fare)}</span>),
        },
    ];

    return (
        <>
            <PageHeader eyebrow="Operations" title="Tickets" subtitle="Every ticket issued by this company's conductors" />

            <div className="d-flex flex-wrap gap-2 mb-3 align-items-center">
                <div className="input-group" style={{ maxWidth: 300 }}>
                    <span className="input-group-text bg-body"><i className="bi bi-search" /></span>
                    <input className="form-control" placeholder="Trip reference or bus #" value={search} onChange={(e) => setSearch(e.target.value)} />
                </div>
                <input type="date" className="form-control w-auto" aria-label="From date" value={filters.from} onChange={(e) => setFilter('from', e.target.value)} />
                <span className="text-body-secondary small">to</span>
                <input type="date" className="form-control w-auto" aria-label="To date" value={filters.to} onChange={(e) => setFilter('to', e.target.value)} />
                <select className="form-select w-auto" value={filters.payment_method} onChange={(e) => setFilter('payment_method', e.target.value)}>
                    <option value="">All payments</option>
                    {PAYMENT_METHODS.map((m) => <option key={m} value={m}>{m}</option>)}
                </select>
                {meta && <span className="small text-body-secondary ms-auto">{meta.total.toLocaleString()} tickets</span>}
            </div>

            <DataTable columns={columns} rows={rows} loading={loading} empty="No tickets match these filters." />
            <Pagination meta={meta} page={page} onPage={setPage} />
        </>
    );
}
