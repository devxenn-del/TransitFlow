import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { swal as Swal } from '../../lib/ui.js';

import { useAuth } from '../../auth/AuthContext.jsx';
import DataTable from '../../components/DataTable.jsx';
import DenominationTable from '../../components/DenominationTable.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import { remittances } from '../../lib/api.js';
import { openReceipt } from '../../lib/receipt.js';
import { useList } from '../../lib/useList.js';
import { notifyError, notifySuccess } from '../../lib/ui.js';

const DENOMS = [1000, 500, 200, 100, 50, 20, 10, 5, 1];
const peso = (n) => `₱${Number(n ?? 0).toLocaleString()}`;
const dt = (v) => (v ? new Date(v).toLocaleString() : '—');
const blankDen = () => Object.fromEntries(DENOMS.map((d) => [`q${d}`, '']));

const STAGES = [
    { key: 'pending', label: 'To receive' },
    { key: 'received', label: 'To approve' },
    { key: 'approved', label: 'Approved' },
    { key: '', label: 'All' },
];

function stageBadge(t) {
    if (t.remittance_approved_at) return <span className="badge text-bg-success">approved</span>;
    if (t.remittance_received_at) return <span className="badge text-bg-info">received</span>;
    return <span className="badge text-bg-secondary">to receive</span>;
}

function VarianceBadge({ v }) {
    if (v == null) return <span className="text-muted small">—</span>;
    if (v < 0) return <span className="badge text-bg-danger">short {peso(-v)}</span>;
    if (v > 0) return <span className="badge text-bg-warning">over {peso(v)}</span>;
    return <span className="badge text-bg-success">exact</span>;
}

export default function Remittances() {
    const { can } = useAuth();
    const [stage, setStage] = useState('pending');
    const [receiving, setReceiving] = useState(null); // trip row
    const [den, setDen] = useState(blankDen());
    const [busy, setBusy] = useState(false);

    const fetcher = useCallback(
        (params) => remittances.list({ ...params, ...(stage ? { stage } : {}) }),
        [stage],
    );
    const { rows, meta, loading, page, setPage, reload } = useList(fetcher, { deps: [stage] });

    const denTotal = useMemo(() => DENOMS.reduce((s, d) => s + d * (Number(den[`q${d}`]) || 0), 0), [den]);

    const tripRef = (t) => t.reference ?? `#${t.id}`;

    // Hold a short-lived processing lock while the Receive modal is open.
    const heartbeat = useRef(null);
    const closeReceive = useCallback(() => {
        clearInterval(heartbeat.current);
        setReceiving((t) => { if (t) remittances.unlock(t.id).catch(() => {}); return null; });
    }, []);
    const openReceive = async (t) => {
        try {
            await remittances.lock(t.id);
        } catch (err) {
            notifyError(err);
            reload();
            return;
        }
        setDen(blankDen());
        setReceiving(t);
        clearInterval(heartbeat.current);
        heartbeat.current = setInterval(() => remittances.lock(t.id).catch(() => {}), 90_000);
    };
    useEffect(() => () => clearInterval(heartbeat.current), []);

    const submitReceive = async (e) => {
        e.preventDefault();
        setBusy(true);
        try {
            const payload = {};
            DENOMS.forEach((d) => { payload[`q${d}`] = Number(den[`q${d}`]) || 0; });
            await remittances.receive(receiving.id, payload);
            notifySuccess(`Received — counted ${peso(denTotal)}.`);
            clearInterval(heartbeat.current);
            setReceiving(null);
            reload();
        } catch (err) {
            notifyError(err);
        } finally {
            setBusy(false);
        }
    };

    const approve = async (t) => {
        const { isConfirmed } = await Swal.fire({
            title: `Approve remittance for ${tripRef(t)}?`,
            html: `<div class="text-start small text-muted">Remitted <b>${peso(t.remittance.remitted_amount)}</b> · counted <b>${peso(t.remittance.counted_total)}</b></div>`,
            showCancelButton: true, confirmButtonText: 'Approve',
        });
        if (!isConfirmed) return;
        try { await remittances.approve(t.id); notifySuccess('Remittance approved.'); reload(); }
        catch (err) { notifyError(err); }
    };

    const voidReceipt = async (t) => {
        const { isConfirmed, value } = await Swal.fire({
            title: `Void the received count for ${tripRef(t)}?`,
            html: '<input id="reason" class="swal2-input" placeholder="Reason (required)">'
                + '<input id="vpin" type="password" inputmode="numeric" class="swal2-input" placeholder="Your void PIN">',
            footer: 'Set your void PIN from the account menu (top-right) if you haven\'t yet.',
            focusConfirm: false, showCancelButton: true, confirmButtonText: 'Void', customClass: { confirmButton: 'btn btn-danger px-4', cancelButton: 'btn btn-light px-4' },
            preConfirm: () => {
                const reason = document.getElementById('reason').value;
                if (!reason) return Swal.showValidationMessage('A reason is required');
                const out = { reason };
                const vp = document.getElementById('vpin');
                if (vp) out.pin = vp.value;
                return out;
            },
        });
        if (!isConfirmed) return;
        try { await remittances.void(t.id, value); notifySuccess('Received count voided.'); reload(); }
        catch (err) { notifyError(err); }
    };

    const toggleFlag = async (t) => {
        if (t.remittance_flagged) {
            try { await remittances.flag(t.id, false); notifySuccess('Flag cleared.'); reload(); } catch (e) { notifyError(e); }
            return;
        }
        const { isConfirmed, value } = await Swal.fire({
            title: 'Flag this remittance', input: 'text', inputLabel: 'Note',
            showCancelButton: true, confirmButtonText: 'Flag', customClass: { confirmButton: 'btn btn-accent px-4', cancelButton: 'btn btn-light px-4' },
            inputValidator: (v) => (!v ? 'A note is required' : undefined),
        });
        if (!isConfirmed) return;
        try { await remittances.flag(t.id, true, value); notifySuccess('Flagged.'); reload(); } catch (e) { notifyError(e); }
    };

    const columns = useMemo(() => [
        {
            key: 'trip', header: 'Trip',
            render: (t) => (
                <div>
                    <div className="fw-semibold font-monospace small">{t.reference ?? `#${t.id}`}</div>
                    <div className="small text-muted">{t.bus_number} · {t.conductor?.name} · ended {dt(t.ended_at)}</div>
                </div>
            ),
        },
        { key: 'collected', header: 'Collected', className: 'text-end', render: (t) => peso(t.remittance.collected) },
        { key: 'remitted', header: 'Remitted', className: 'text-end', render: (t) => peso(t.remittance.remitted_amount) },
        {
            key: 'counted', header: 'Counted', className: 'text-end',
            render: (t) => (t.remittance.counted_total == null
                ? <span className="text-muted small">—</span>
                : <div>{peso(t.remittance.counted_total)}<div><VarianceBadge v={t.remittance.count_variance} /></div></div>),
        },
        {
            key: 'stage', header: 'Stage',
            render: (t) => (
                <>
                    {stageBadge(t)}
                    {t.remittance_flagged && <i className="bi bi-flag-fill text-warning ms-1" title={t.remittance_flag_note} />}
                    {t.remittance_locked && !t.remittance_locked_by_me && (
                        <span className="badge text-bg-secondary ms-1" title={`${t.remittance_locked_by} is processing this`}>
                            <i className="bi bi-lock-fill me-1" />{t.remittance_locked_by}
                        </span>
                    )}
                </>
            ),
        },
        {
            key: 'actions', header: '', className: 'text-end text-nowrap',
            render: (t) => (
                <>
                    {!t.remittance_received_at && can('remittances.receive') && (
                        <button className="btn btn-sm btn-accent me-1"
                            disabled={t.remittance_locked && !t.remittance_locked_by_me}
                            onClick={() => openReceive(t)}>
                            <i className="bi bi-cash-stack me-1" /> Receive
                        </button>
                    )}
                    {t.remittance_received_at && !t.remittance_approved_at && can('remittances.void') && (
                        <button className="btn btn-sm btn-outline-danger me-1" onClick={() => voidReceipt(t)}>Void</button>
                    )}
                    {t.remittance_received_at && !t.remittance_approved_at && can('remittances.approve') && (
                        <button className="btn btn-sm btn-primary me-1" onClick={() => approve(t)}>
                            <i className="bi bi-check2-circle me-1" /> Approve
                        </button>
                    )}
                    {can('remittances.approve') && (
                        <button className="btn btn-sm btn-outline-warning me-1" title="Flag" onClick={() => toggleFlag(t)}>
                            <i className={`bi ${t.remittance_flagged ? 'bi-flag-fill' : 'bi-flag'}`} />
                        </button>
                    )}
                    <button className="btn btn-sm btn-outline-secondary" title="Print remittance receipt"
                        onClick={() => openReceipt({ src: 'company', kind: 'remittance', id: t.id })}>
                        <i className="bi bi-printer" />
                    </button>
                </>
            ),
        },
    ], [can]);

    return (
        <>
            <PageHeader
                eyebrow="Operations"
                title="Remittances"
                subtitle="Receive each trip's cash (count denominations) then approve. Voiding a received count is manager-only."
            />

            <div className="btn-group btn-group-sm mb-3" role="group">
                {STAGES.map((s) => (
                    <button key={s.key || 'all'} type="button"
                        className={`btn btn-outline-secondary${stage === s.key ? ' active' : ''}`}
                        onClick={() => setStage(s.key)}>
                        {s.label}
                    </button>
                ))}
            </div>

            <DataTable columns={columns} rows={rows} loading={loading} empty="Nothing in this stage." />
            <Pagination meta={meta} page={page} onPage={setPage} />

            <Modal
                open={!!receiving}
                title={receiving ? `Receive ${tripRef(receiving)} — count the cash` : ''}
                onClose={closeReceive}
                footer={(
                    <>
                        <button className="btn btn-light" onClick={closeReceive}>Cancel</button>
                        <button className="btn btn-primary" form="recv-form" disabled={busy}>
                            {busy && <span className="spinner-border spinner-border-sm me-2" />}Receive · {peso(denTotal)}
                        </button>
                    </>
                )}
            >
                {receiving && (
                    <form id="recv-form" onSubmit={submitReceive} className="vstack gap-2">
                        <div className="small text-muted mb-1">
                            Remitted by conductor: <b>{peso(receiving.remittance.remitted_amount)}</b>
                        </div>
                        <DenominationTable value={den} onChange={setDen} />
                        {denTotal !== Number(receiving.remittance.remitted_amount) && denTotal > 0 && (
                            <div className={`small ${denTotal < receiving.remittance.remitted_amount ? 'text-danger' : 'text-warning'}`}>
                                {denTotal < receiving.remittance.remitted_amount ? 'Short ' : 'Over '}
                                {peso(Math.abs(denTotal - receiving.remittance.remitted_amount))} vs remitted
                            </div>
                        )}
                    </form>
                )}
            </Modal>
        </>
    );
}
