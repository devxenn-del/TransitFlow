import { useCallback, useMemo, useState } from 'react';
import { swal as Swal } from '../../lib/ui.js';

import { useAuth } from '../../auth/AuthContext.jsx';
import DataTable from '../../components/DataTable.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import StatusBadge from '../../components/StatusBadge.jsx';
import { tripMonitor } from '../../lib/api.js';
import { openReceipt } from '../../lib/receipt.js';
import { useList } from '../../lib/useList.js';
import { notifyError, notifySuccess } from '../../lib/ui.js';

const peso = (n) => `₱${Number(n ?? 0).toFixed(2)}`;
const dt = (v) => (v ? new Date(v).toLocaleString() : '—');

const FILTERS = [
    { key: 'all', label: 'All', params: {} },
    { key: 'live', label: 'Live', params: { live: 1 } },
    { key: 'pending', label: 'Pending approval', params: { pending_approval: 1 } },
    { key: 'flagged', label: 'Flagged', params: { flagged: 1 } },
];

function VarianceBadge({ r }) {
    if (r.remitted_amount === null) return <span className="text-muted small">—</span>;
    if (r.short > 0) return <span className="badge text-bg-danger">short {peso(r.short)}</span>;
    if (r.excess > 0) return <span className="badge text-bg-warning">excess {peso(r.excess)}</span>;
    return <span className="badge text-bg-success">balanced</span>;
}

function Detail({ payload, canForceEnd, onAction }) {
    const trip = payload.data;
    const r = trip.remittance;
    const [busy, setBusy] = useState(false);

    const run = async (fn) => {
        setBusy(true);
        try { await fn(); } finally { setBusy(false); }
    };

    const forceEnd = () => run(async () => {
        const { isConfirmed, value } = await Swal.fire({
            title: 'Force-end this trip?', input: 'text', inputLabel: 'Reason', inputPlaceholder: 'e.g. conductor unreachable',
            showCancelButton: true, confirmButtonText: 'Force-end', customClass: { confirmButton: 'btn btn-danger px-4', cancelButton: 'btn btn-light px-4' },
            inputValidator: (v) => (!v ? 'A reason is required' : undefined),
        });
        if (!isConfirmed) return;
        try { await tripMonitor.forceEnd(trip.id, value); notifySuccess('Trip force-ended.'); onAction(); }
        catch (e) { notifyError(e); }
    });

    const Row = ({ label, value, strong }) => (
        <div className="d-flex justify-content-between py-1 border-bottom">
            <span className="text-muted">{label}</span>
            <span className={strong ? 'fw-semibold' : ''}>{value}</span>
        </div>
    );

    return (
        <div className="vstack gap-3">
            <div className="d-flex flex-wrap gap-3">
                <div><div className="tf-stat-label">Status</div><StatusBadge value={trip.status.toLowerCase()} /></div>
                <div><div className="tf-stat-label">Conductor</div><div className="fw-semibold">{trip.conductor?.name ?? '—'}</div></div>
                <div><div className="tf-stat-label">Bus</div><div className="fw-semibold">{trip.bus_number ?? '—'}</div></div>
                <div><div className="tf-stat-label">Coverage</div><div className="fw-semibold">{trip.coverage_origin} → {trip.coverage_destination}</div></div>
                <div><div className="tf-stat-label">Started</div><div className="fw-semibold">{dt(trip.started_at)}</div></div>
                <div><div className="tf-stat-label">Ended</div><div className="fw-semibold">{dt(trip.ended_at)}</div></div>
            </div>

            {trip.force_ended_at && (
                <div className="alert alert-warning small mb-0">
                    Force-ended by <b>{trip.force_ended_by}</b> — {trip.force_ended_reason}
                </div>
            )}
            {trip.remittance_flagged && (
                <div className="alert alert-warning small mb-0"><i className="bi bi-flag-fill me-1" />{trip.remittance_flag_note}</div>
            )}

            <div className="row g-3">
                <div className="col-md-6">
                    <div className="card h-100">
                        <div className="card-header bg-white fw-semibold">Remittance (§4.3)</div>
                        <div className="card-body">
                            <Row label={`Collected (${r.ticket_count} tickets)`} value={peso(r.collected)} />
                            <Row label={`Dispatch (${r.dispatch_count})`} value={`−${peso(r.total_dispatch)}`} />
                            <Row label="Suggested remit" value={peso(r.suggested_remit)} strong />
                            <Row label="Remitted" value={r.remitted_amount === null ? '—' : peso(r.remitted_amount)} />
                            <Row label="Variance" value={<VarianceBadge r={r} />} />
                            {r.refunded > 0 && <Row label={`Refunded (${r.refunded_count})`} value={peso(r.refunded)} />}
                            {r.approved_at && <Row label="Approved" value={`${dt(r.approved_at)} · ${r.approved_by ?? ''}`} />}
                        </div>
                    </div>
                </div>
                <div className="col-md-6">
                    <div className="card h-100">
                        <div className="card-header bg-white fw-semibold">Splits</div>
                        <div className="card-body">
                            <Row label="Cash" value={peso(r.by_payment_method.Cash)} />
                            <Row label="QR" value={peso(r.by_payment_method.QR)} />
                            <Row label="E-Wallet" value={peso(r.by_payment_method['E-Wallet'])} />
                            <Row label="Terminal boarding" value={peso(r.by_boarding_type.Terminal)} />
                            <Row label="Pickup boarding" value={peso(r.by_boarding_type.Pickup)} />
                            <Row label="Passenger fares" value={peso(r.by_fare_mode.passenger)} />
                            <Row label="Article sales" value={peso(r.by_fare_mode.article)} />
                        </div>
                    </div>
                </div>
            </div>

            {payload.dispatches.length > 0 && (
                <div className="card">
                    <div className="card-header bg-white fw-semibold">Dispatches</div>
                    <table className="table table-sm mb-0">
                        <tbody>
                            {payload.dispatches.map((d) => (
                                <tr key={d.id}><td>{d.barker_name}</td><td className="text-end fare-amount">{peso(d.amount)}</td></tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {trip.status === 'Arrived' && (
                <div className="small text-muted">
                    Remittance stage: <b>{r.stage}</b>. Receive &amp; approve it on the <b>Remittances</b> page.
                </div>
            )}

            <div className="d-flex gap-2 flex-wrap">
                {trip.is_live && canForceEnd && (
                    <button className="btn btn-outline-danger" disabled={busy} onClick={forceEnd}>
                        <i className="bi bi-stop-circle me-1" /> Force-end
                    </button>
                )}
                {['Arrived', 'Cancelled'].includes(trip.status) && (
                    <>
                        <button className="btn btn-outline-secondary" onClick={() => openReceipt({ src: 'monitor', kind: 'arrival', id: trip.id })}>
                            <i className="bi bi-printer me-1" /> {trip.status === 'Cancelled' ? 'Cancellation slip' : 'Arrival slip'}
                        </button>
                        <button className="btn btn-outline-secondary" onClick={() => openReceipt({ src: 'monitor', kind: 'remittance', id: trip.id })}>
                            <i className="bi bi-printer me-1" /> Remittance receipt
                        </button>
                    </>
                )}
            </div>
        </div>
    );
}

export default function TripMonitor() {
    const { can } = useAuth();
    const canForceEnd = can('tripmonitoring.forceend');
    const [filter, setFilter] = useState('all');
    const [detail, setDetail] = useState(null);

    const fetcher = useCallback(
        (params) => tripMonitor.list({ ...params, ...FILTERS.find((f) => f.key === filter).params }),
        [filter],
    );
    const { rows, meta, loading, page, setPage, reload } = useList(fetcher, { deps: [filter] });

    const openDetail = async (id) => {
        try { setDetail(await tripMonitor.get(id)); }
        catch (e) { notifyError(e); }
    };

    const afterAction = async () => {
        reload();
        if (detail) {
            try { setDetail(await tripMonitor.get(detail.data.id)); } catch { setDetail(null); }
        }
    };

    const columns = useMemo(() => [
        {
            key: 'trip', header: 'Trip',
            render: (r) => (
                <div>
                    <div className="fw-semibold font-monospace small">{r.reference ?? `#${r.id}`}</div>
                    <div className="small text-muted">{r.coverage_origin} → {r.coverage_destination} · {dt(r.started_at)}</div>
                </div>
            ),
        },
        { key: 'conductor', header: 'Conductor', render: (r) => r.conductor?.name ?? '—' },
        { key: 'bus', header: 'Bus', render: (r) => r.bus_number ?? '—' },
        {
            key: 'status', header: 'Status',
            render: (r) => (
                <>
                    <StatusBadge value={r.status.toLowerCase()} />
                    {r.remittance_flagged && <i className="bi bi-flag-fill text-warning ms-1" title={r.remittance_flag_note} />}
                    {r.force_ended_at && <i className="bi bi-stop-circle text-danger ms-1" title="Force-ended" />}
                </>
            ),
        },
        { key: 'collected', header: 'Collected', className: 'text-end', render: (r) => peso(r.remittance.collected) },
        { key: 'suggested', header: 'Suggested', className: 'text-end', render: (r) => peso(r.remittance.suggested_remit) },
        {
            key: 'remit', header: 'Remitted', className: 'text-end',
            render: (r) => (
                <div>
                    {r.remittance.remitted_amount === null ? '—' : peso(r.remittance.remitted_amount)}
                    <div><VarianceBadge r={r.remittance} /></div>
                </div>
            ),
        },
        {
            key: 'approved', header: 'Remittance',
            render: (r) => (r.remittance_approved_at
                ? <span className="badge text-bg-success">approved</span>
                : r.status === 'Arrived' ? <span className="badge text-bg-secondary">pending</span> : <span className="text-muted small">—</span>),
        },
        {
            key: 'actions', header: '', className: 'text-end',
            render: (r) => (
                <button className="btn btn-sm btn-outline-secondary" onClick={() => openDetail(r.id)}>
                    <i className="bi bi-eye me-1" /> View
                </button>
            ),
        },
    ], []);

    return (
        <>
            <PageHeader
                eyebrow="Operations"
                title="Trip Monitoring"
                subtitle="Every trip in the company — live board, remittance review and force-end."
            />

            <div className="btn-group btn-group-sm mb-3" role="group">
                {FILTERS.map((f) => (
                    <button key={f.key} type="button"
                        className={`btn btn-outline-secondary${filter === f.key ? ' active' : ''}`}
                        onClick={() => setFilter(f.key)}>
                        {f.label}
                    </button>
                ))}
            </div>

            <DataTable columns={columns} rows={rows} loading={loading} empty="No trips match this filter." />
            <Pagination meta={meta} page={page} onPage={setPage} />

            <Modal
                open={!!detail}
                title={detail ? (detail.data.reference ?? `Trip #${detail.data.id}`) : ''}
                onClose={() => setDetail(null)}
                size="xl"
                footer={<button className="btn btn-light" onClick={() => setDetail(null)}>Close</button>}
            >
                {detail && <Detail payload={detail} canForceEnd={canForceEnd} onAction={afterAction} />}
            </Modal>
        </>
    );
}
