import { useEffect, useState } from 'react';

import DataTable from '../../components/DataTable.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import PrinterStatusBadge, { PRINTER_STATUSES } from '../../components/PrinterStatusBadge.jsx';
import { thermalPrinters } from '../../lib/api.js';
import { LIST_PAGE_SIZE, useList } from '../../lib/useList.js';

const EMPTY = 'No thermal printers have been assigned to your company yet. Please contact your system administrator for assistance.';
const fmtDate = (v) => (v ? new Date(`${v}T00:00:00`).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '—');
const muted = (text) => <span className="text-body-secondary">{text}</span>;
const busLabel = (bus) => (bus ? `${bus.bus_number}${bus.plate_number ? ` · ${bus.plate_number}` : ''}` : null);

/**
 * The thermal printers the platform issued to this company, and the bus
 * each one is on. View only — the inventory, company and bus assignments
 * are all managed by the Super Admin, and the API has no write routes here.
 */
export default function ThermalPrinters() {
    const [search, setSearch] = useState('');
    const [q, setQ] = useState('');
    const [status, setStatus] = useState('');
    const [viewing, setViewing] = useState(null);
    const { rows, meta, loading, page, setPage } = useList(
        (params) => thermalPrinters.list({ ...params, q: q || undefined, status: status || undefined }),
        { perPage: LIST_PAGE_SIZE, deps: [q, status] },
    );

    useEffect(() => {
        const t = setTimeout(() => setQ(search.trim()), 300);
        return () => clearTimeout(t);
    }, [search]);

    const filtered = !!(q || status);

    const columns = [
        { key: 'thermal_id', header: 'Thermal ID', className: 'text-nowrap', render: (p) => <code className="fw-semibold">{p.thermal_id}</code> },
        { key: 'device_id', header: 'Device ID', className: 'text-nowrap', render: (p) => <code>{p.device_id}</code> },
        { key: 'mac_address', header: 'MAC address', className: 'text-nowrap', render: (p) => p.mac_address ? <code>{p.mac_address}</code> : muted('—') },
        { key: 'model', header: 'Model' },
        { key: 'bus', header: 'Assigned bus', className: 'text-nowrap', render: (p) => busLabel(p.bus) ?? muted('Not Assigned') },
        { key: 'status', header: 'Status', render: (p) => <PrinterStatusBadge value={p.status} /> },
        { key: 'date_issued', header: 'Date issued', className: 'text-nowrap small', render: (p) => fmtDate(p.date_issued) },
        {
            key: 'actions', header: <span className="visually-hidden">Actions</span>, className: 'text-end',
            render: (p) => (
                <button className="btn btn-sm btn-outline-secondary" onClick={() => setViewing(p)} aria-label={`View ${p.thermal_id}`}>
                    <i className="bi bi-eye" />
                </button>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Fleet"
                title="Thermal Printers"
                subtitle="Printers issued to your company and the bus each one is assigned to."
                actions={<span className="badge tf-badge-inactive align-self-center"><i className="bi bi-eye me-1" />View only</span>}
            />

            <div className="d-flex flex-wrap gap-2 mb-3">
                <div className="input-group flex-grow-1" style={{ maxWidth: 420 }}>
                    <span className="input-group-text bg-body"><i className="bi bi-search" /></span>
                    <input
                        className="form-control"
                        placeholder="Search Thermal ID, Device ID, MAC, model or bus"
                        aria-label="Search thermal printers"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>
                <select className="form-select w-auto" aria-label="Filter by status" value={status} onChange={(e) => setStatus(e.target.value)}>
                    <option value="">All statuses</option>
                    {PRINTER_STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
                {meta && <span className="align-self-center small text-body-secondary ms-auto">{meta.total} {meta.total === 1 ? 'printer' : 'printers'}</span>}
            </div>

            <DataTable columns={columns} rows={rows} loading={loading} empty={filtered ? 'No printers match these filters.' : EMPTY} />
            <Pagination meta={meta} page={page} onPage={setPage} />

            <Modal open={!!viewing} title={viewing?.thermal_id ?? ''} onClose={() => setViewing(null)}>
                {viewing && (
                    <dl className="row mb-0">
                        <dt className="col-5">Thermal ID</dt><dd className="col-7"><code>{viewing.thermal_id}</code></dd>
                        <dt className="col-5">Device ID</dt><dd className="col-7"><code>{viewing.device_id}</code></dd>
                        <dt className="col-5">MAC address</dt><dd className="col-7">{viewing.mac_address ? <code>{viewing.mac_address}</code> : muted('—')}</dd>
                        <dt className="col-5">Model</dt><dd className="col-7">{viewing.model}</dd>
                        {viewing.serial_number && (<><dt className="col-5">Serial no.</dt><dd className="col-7">{viewing.serial_number}</dd></>)}
                        <dt className="col-5">Assigned bus</dt><dd className="col-7">{busLabel(viewing.bus) ?? muted('Not Assigned')}</dd>
                        <dt className="col-5">Status</dt><dd className="col-7"><PrinterStatusBadge value={viewing.status} /></dd>
                        <dt className="col-5">Date issued</dt><dd className="col-7">{fmtDate(viewing.date_issued)}</dd>
                    </dl>
                )}
                <p className="small text-body-secondary mt-3 mb-0">
                    <i className="bi bi-info-circle me-1" />Changes to printers or their bus assignments are made by your system administrator.
                </p>
            </Modal>
        </>
    );
}
