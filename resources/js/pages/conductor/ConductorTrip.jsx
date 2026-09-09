import { useCallback, useEffect, useMemo, useState } from 'react';
import { swal as Swal } from '../../lib/ui.js';

import { useAuth } from '../../auth/AuthContext.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import StatusBadge from '../../components/StatusBadge.jsx';
import { conductor } from '../../lib/api.js';
import { openReceipt } from '../../lib/receipt.js';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

const peso = (n) => `₱${Number(n ?? 0).toFixed(2)}`;

/* ---------- Start-trip form ---------- */
function StartTripForm({ onStarted }) {
    const [buses, setBuses] = useState([]);
    const [drivers, setDrivers] = useState([]);
    const [terminals, setTerminals] = useState([]);
    const [coverage, setCoverage] = useState({ origins: [], pairs: [] });
    const [form, setForm] = useState({ bus_id: '', driver_id: '', origin: '', coverage_origin: '', coverage_destination: '' });
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        Promise.all([
            conductor.lookupBuses(), conductor.lookupDrivers(), conductor.lookupTerminals(), conductor.lookupCoverage(),
        ]).then(([b, d, t, c]) => {
            setBuses(b); setDrivers(d); setTerminals(t); setCoverage(c);
        }).catch((e) => notifyError(e, 'Could not load trip options.'));
    }, []);

    const destinations = useMemo(
        () => coverage.pairs.filter((p) => p.origin === form.coverage_origin).map((p) => p.destination),
        [coverage.pairs, form.coverage_origin],
    );

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        try {
            await conductor.startTrip(form);
            notifySuccess('Trip started.');
            onStarted();
        } catch (err) {
            notifyError(err);
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="card" style={{ maxWidth: 640 }}>
            <div className="card-body">
                <h2 className="h5 mb-3">Start a trip</h2>
                {buses.length === 0 && (
                    <div className="alert alert-warning small">No buses are assigned to your account yet — ask an admin.</div>
                )}
                <form onSubmit={submit} className="vstack gap-3">
                    <div className="row g-2">
                        <div className="col-md-6">
                            <label className="form-label">Bus *</label>
                            <select className="form-select" required value={form.bus_id} onChange={(e) => setForm({ ...form, bus_id: e.target.value })}>
                                <option value="">Select…</option>
                                {buses.map((b) => <option key={b.id} value={b.id}>{b.bus_number} · {b.plate_number}</option>)}
                            </select>
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Driver *</label>
                            <select className="form-select" required value={form.driver_id} onChange={(e) => setForm({ ...form, driver_id: e.target.value })}>
                                <option value="">Select…</option>
                                {drivers.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                            </select>
                        </div>
                    </div>
                    <div>
                        <label className="form-label">Starting terminal *</label>
                        <select className="form-select" required value={form.origin} onChange={(e) => setForm({ ...form, origin: e.target.value })}>
                            <option value="">Select…</option>
                            {terminals.map((t) => (
                                <option key={t.id} value={t.name}>{t.name}{t.boarding_mode === 'Pickup' ? ' (pickup only)' : ''}</option>
                            ))}
                        </select>
                    </div>
                    <div className="row g-2">
                        <div className="col-md-6">
                            <label className="form-label">Coverage from *</label>
                            <select className="form-select" required value={form.coverage_origin} onChange={(e) => setForm({ ...form, coverage_origin: e.target.value, coverage_destination: '' })}>
                                <option value="">Select…</option>
                                {coverage.origins.map((o) => <option key={o} value={o}>{o}</option>)}
                            </select>
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Coverage to *</label>
                            <select className="form-select" required value={form.coverage_destination} disabled={!form.coverage_origin} onChange={(e) => setForm({ ...form, coverage_destination: e.target.value })}>
                                <option value="">Select…</option>
                                {destinations.map((d) => <option key={d} value={d}>{d}</option>)}
                            </select>
                        </div>
                    </div>
                    <button className="btn btn-accent" disabled={busy || buses.length === 0}>
                        {busy ? <span className="spinner-border spinner-border-sm me-2" /> : <i className="bi bi-play-fill me-1" />}
                        Start trip
                    </button>
                </form>
            </div>
        </div>
    );
}

/* ---------- Group-ticket panel ---------- */
function GroupTicket({ trip, faresList, matrixTypes, onIssued }) {
    const blankLine = () => ({ passenger_type_id: '', route_id: '', quantity: 1 });
    const [boarding, setBoarding] = useState(trip.status === 'OnTrip' ? 'Pickup' : 'Terminal');
    const [payment, setPayment] = useState('Cash');
    const [qrRef, setQrRef] = useState('');
    const [lines, setLines] = useState([blankLine()]);
    const [busy, setBusy] = useState(false);

    const setLine = (i, patch) => setLines((ls) => ls.map((l, j) => (j === i ? { ...l, ...patch } : l)));
    const fareOf = (routeId) => Number(faresList.find((f) => String(f.route_id) === String(routeId))?.amount ?? 0);
    const estimate = lines.reduce((s, l) => s + fareOf(l.route_id) * (Number(l.quantity) || 0), 0);

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        try {
            const res = await conductor.issueTicketGroup({
                boarding_type: boarding,
                payment_method: payment,
                qr_reference: payment === 'QR' ? qrRef : null,
                lines: lines.map((l) => ({
                    passenger_type_id: Number(l.passenger_type_id),
                    route_id: Number(l.route_id) || null,
                    quantity: Number(l.quantity) || 1,
                })),
            });
            notifySuccess(`Group ticket — ${res.group.passenger_count} pax, ${peso(res.group.total_fare)}`);
            setLines([blankLine()]);
            setQrRef('');
            onIssued();
        } catch (err) {
            notifyError(err);
        } finally {
            setBusy(false);
        }
    };

    return (
        <form onSubmit={submit} className="card-body vstack gap-2">
            <div className="btn-group btn-group-sm" role="group">
                {['Terminal', 'Pickup'].map((b) => (
                    <button key={b} type="button"
                        className={`btn btn-outline-secondary${boarding === b ? ' active' : ''}`}
                        disabled={b === 'Terminal' && trip.status === 'OnTrip'}
                        onClick={() => setBoarding(b)}>
                        Via {b}
                    </button>
                ))}
            </div>

            {lines.map((l, i) => (
                <div className="row g-1 align-items-end" key={i}>
                    <div className="col-5">
                        {i === 0 && <label className="form-label small mb-1">Passenger type</label>}
                        <select className="form-select form-select-sm" required value={l.passenger_type_id}
                            onChange={(e) => setLine(i, { passenger_type_id: e.target.value })}>
                            <option value="">Select…</option>
                            {matrixTypes.map((t) => <option key={t.id} value={t.id}>{t.name}{t.discount_percent > 0 ? ` (−${t.discount_percent}%)` : ''}</option>)}
                        </select>
                    </div>
                    <div className="col-4">
                        {i === 0 && <label className="form-label small mb-1">Destination</label>}
                        <select className="form-select form-select-sm" required value={l.route_id}
                            onChange={(e) => setLine(i, { route_id: e.target.value })}>
                            <option value="">Select…</option>
                            {faresList.map((f) => <option key={f.route_id} value={f.route_id}>{f.destination} · {peso(f.amount)}</option>)}
                        </select>
                    </div>
                    <div className="col-2">
                        {i === 0 && <label className="form-label small mb-1">Qty</label>}
                        <input type="number" min="1" max="50" className="form-control form-control-sm" value={l.quantity}
                            onChange={(e) => setLine(i, { quantity: e.target.value })} />
                    </div>
                    <div className="col-1">
                        <button type="button" className="btn btn-sm btn-link text-danger p-0"
                            disabled={lines.length === 1} onClick={() => setLines((ls) => ls.filter((_, j) => j !== i))}>
                            <i className="bi bi-x-circle" />
                        </button>
                    </div>
                </div>
            ))}

            <button type="button" className="btn btn-sm btn-outline-secondary align-self-start"
                disabled={lines.length >= 30} onClick={() => setLines((ls) => [...ls, blankLine()])}>
                <i className="bi bi-plus-lg me-1" /> Add line
            </button>

            <div className="row g-2">
                <div className="col-7">
                    <label className="form-label">Payment</label>
                    <select className="form-select" value={payment} onChange={(e) => setPayment(e.target.value)}>
                        <option>Cash</option><option>QR</option><option>E-Wallet</option>
                    </select>
                </div>
                <div className="col-5 d-flex align-items-end justify-content-end">
                    <span className="fare-amount fw-semibold">≈ {peso(estimate)}</span>
                </div>
            </div>
            {payment === 'QR' && (
                <div>
                    <label className="form-label">QR reference (last 6)</label>
                    <input className="form-control" maxLength={6} value={qrRef} onChange={(e) => setQrRef(e.target.value.toUpperCase())} />
                </div>
            )}

            <button className="btn btn-primary mt-1" disabled={busy}>
                {busy ? <span className="spinner-border spinner-border-sm me-2" /> : <i className="bi bi-people me-1" />}
                Issue group ticket
            </button>
        </form>
    );
}

/* ---------- Ticketing panel ---------- */
function Ticketing({ trip, onChange }) {
    const [fares, setFares] = useState({ terminal: [], pickup: [] });
    const [types, setTypes] = useState([]);
    const [tickets, setTickets] = useState([]);
    const [mode, setMode] = useState('single');
    const [form, setForm] = useState({
        passenger_type_id: '', route_id: '', article_label: '', manual_amount: '',
        boarding_type: trip.status === 'OnTrip' ? 'Pickup' : 'Terminal',
        payment_method: 'Cash', qr_reference: '', quantity: 1,
    });
    const [busy, setBusy] = useState(false);

    const reloadTickets = useCallback(() => conductor.tripTickets(trip.id).then(setTickets).catch(() => {}), [trip.id]);

    useEffect(() => {
        conductor.tripFares(trip.id).then(setFares).catch(() => {});
        conductor.lookupPassengerTypes().then(setTypes).catch(() => {});
        reloadTickets();
    }, [trip.id, reloadTickets]);

    const pt = types.find((t) => String(t.id) === String(form.passenger_type_id));
    const isManual = pt?.fare_mode === 'Manual Amount';
    const faresList = form.boarding_type === 'Terminal' ? fares.terminal : fares.pickup;
    const matrixTypes = types.filter((t) => t.fare_mode !== 'Manual Amount');
    const groupFares = (trip.status === 'OnTrip' ? fares.pickup : fares.terminal);

    const issue = async (e) => {
        e.preventDefault();
        setBusy(true);
        try {
            const payload = {
                passenger_type_id: Number(form.passenger_type_id),
                route_id: isManual ? null : Number(form.route_id) || null,
                article_label: isManual ? form.article_label || null : null,
                manual_amount: isManual && !form.article_label ? Number(form.manual_amount) : null,
                boarding_type: form.boarding_type,
                payment_method: form.payment_method,
                qr_reference: form.payment_method === 'QR' ? form.qr_reference : null,
                quantity: Number(form.quantity) || 1,
            };
            const res = await conductor.issueTicket(payload);
            notifySuccess(`${res.count} ticket(s) — ${peso(res.total_fare)}`);
            setForm((f) => ({ ...f, route_id: '', article_label: '', manual_amount: '', qr_reference: '', quantity: 1 }));
            reloadTickets();
            onChange();
        } catch (err) {
            notifyError(err);
        } finally {
            setBusy(false);
        }
    };

    const total = tickets.reduce((s, t) => s + (t.refunded_at ? 0 : Number(t.fare)), 0);

    return (
        <div className="row g-3">
            <div className="col-lg-5">
                <div className="card">
                    <div className="card-header bg-white d-flex align-items-center justify-content-between">
                        <span className="fw-semibold">Issue ticket</span>
                        <div className="btn-group btn-group-sm" role="group">
                            {['single', 'group'].map((m) => (
                                <button key={m} type="button"
                                    className={`btn btn-outline-secondary${mode === m ? ' active' : ''}`}
                                    onClick={() => setMode(m)}>
                                    {m === 'single' ? 'Single' : 'Group'}
                                </button>
                            ))}
                        </div>
                    </div>
                    {mode === 'group' ? (
                        <GroupTicket
                            trip={trip}
                            faresList={groupFares}
                            matrixTypes={matrixTypes}
                            onIssued={() => { reloadTickets(); onChange(); }}
                        />
                    ) : (
                    <form onSubmit={issue} className="card-body vstack gap-2">
                        <div>
                            <label className="form-label">Passenger type</label>
                            <select className="form-select" required value={form.passenger_type_id} onChange={(e) => setForm({ ...form, passenger_type_id: e.target.value, route_id: '', article_label: '' })}>
                                <option value="">Select…</option>
                                {types.map((t) => <option key={t.id} value={t.id}>{t.name}{t.discount_percent > 0 ? ` (−${t.discount_percent}%)` : ''}</option>)}
                            </select>
                        </div>

                        {isManual ? (
                            (pt.articles ?? []).length > 0 ? (
                                <div>
                                    <label className="form-label">Article</label>
                                    <select className="form-select" required value={form.article_label} onChange={(e) => setForm({ ...form, article_label: e.target.value })}>
                                        <option value="">Select…</option>
                                        {pt.articles.map((a) => <option key={a.id} value={a.label}>{a.label} — {peso(a.amount)}</option>)}
                                    </select>
                                </div>
                            ) : (
                                <div>
                                    <label className="form-label">Amount (₱)</label>
                                    <input type="number" step="0.01" min="0" className="form-control" required value={form.manual_amount} onChange={(e) => setForm({ ...form, manual_amount: e.target.value })} />
                                </div>
                            )
                        ) : (
                            <>
                                <div className="btn-group btn-group-sm" role="group">
                                    {['Terminal', 'Pickup'].map((b) => (
                                        <button
                                            key={b}
                                            type="button"
                                            className={`btn btn-outline-secondary${form.boarding_type === b ? ' active' : ''}`}
                                            disabled={b === 'Terminal' && trip.status === 'OnTrip'}
                                            onClick={() => setForm({ ...form, boarding_type: b, route_id: '' })}
                                        >
                                            Via {b}
                                        </button>
                                    ))}
                                </div>
                                <div>
                                    <label className="form-label">Destination</label>
                                    <select className="form-select" required value={form.route_id} onChange={(e) => setForm({ ...form, route_id: e.target.value })}>
                                        <option value="">Select…</option>
                                        {faresList.map((f) => (
                                            <option key={f.route_id} value={f.route_id}>
                                                {f.origin} → {f.destination} · {peso(f.amount)}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            </>
                        )}

                        <div className="row g-2">
                            <div className="col-7">
                                <label className="form-label">Payment</label>
                                <select className="form-select" value={form.payment_method} onChange={(e) => setForm({ ...form, payment_method: e.target.value })}>
                                    <option>Cash</option>
                                    <option>QR</option>
                                    <option>E-Wallet</option>
                                </select>
                            </div>
                            <div className="col-5">
                                <label className="form-label">Qty</label>
                                <input type="number" min="1" max="50" className="form-control" value={form.quantity} onChange={(e) => setForm({ ...form, quantity: e.target.value })} />
                            </div>
                        </div>
                        {form.payment_method === 'QR' && (
                            <div>
                                <label className="form-label">QR reference (last 6)</label>
                                <input className="form-control" maxLength={6} value={form.qr_reference} onChange={(e) => setForm({ ...form, qr_reference: e.target.value.toUpperCase() })} />
                            </div>
                        )}

                        <button className="btn btn-primary mt-1" disabled={busy}>
                            {busy ? <span className="spinner-border spinner-border-sm me-2" /> : <i className="bi bi-ticket-perforated me-1" />}
                            Issue
                        </button>
                    </form>
                    )}
                </div>
            </div>

            <div className="col-lg-7">
                <div className="card">
                    <div className="card-header bg-white d-flex justify-content-between">
                        <span className="fw-semibold">Tickets ({tickets.length})</span>
                        <span className="fare-amount fw-semibold">{peso(total)}</span>
                    </div>
                    <div className="table-responsive" style={{ maxHeight: 420, overflow: 'auto' }}>
                        <table className="table table-sm mb-0">
                            <thead><tr><th>#</th><th>Passenger</th><th>Route / Article</th><th>Pay</th><th className="text-end">Fare</th><th /></tr></thead>
                            <tbody>
                                {tickets.length === 0 && <tr><td colSpan={6} className="tf-empty">No tickets yet.</td></tr>}
                                {tickets.map((t) => (
                                    <tr key={t.id}>
                                        <td>{t.id}{t.ticket_group_id && <span className="badge text-bg-light border ms-1" title={`Group #${t.ticket_group_id}`}>grp</span>}</td>
                                        <td>{t.passenger_type}</td>
                                        <td className="small">{t.route ? `${t.route.origin} → ${t.route.destination}` : t.article_label ?? '—'}</td>
                                        <td className="small">{t.payment_method}</td>
                                        <td className="text-end fare-amount">{peso(t.fare)}</td>
                                        <td className="text-end">
                                            <button className="btn btn-sm btn-link p-0" title="Print ticket"
                                                onClick={() => openReceipt({ src: 'ticket', id: t.id })}>
                                                <i className="bi bi-printer" />
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    );
}

/* ---------- Dispatch panel ---------- */
function Dispatches({ trip, canIssue, onChange }) {
    const [rows, setRows] = useState([]);
    const [total, setTotal] = useState(0);
    const [form, setForm] = useState({ barker_name: '', amount: '' });
    const [busy, setBusy] = useState(false);

    const reload = useCallback(
        () => conductor.dispatches(trip.id).then((r) => { setRows(r.data); setTotal(r.total); }).catch(() => {}),
        [trip.id],
    );
    useEffect(() => { reload(); }, [reload]);

    const add = async (e) => {
        e.preventDefault();
        setBusy(true);
        try {
            await conductor.addDispatch({ barker_name: form.barker_name, amount: Number(form.amount) });
            notifySuccess('Dispatch recorded.');
            setForm({ barker_name: '', amount: '' });
            reload();
            onChange();
        } catch (err) {
            notifyError(err);
        } finally {
            setBusy(false);
        }
    };

    const remove = async (row) => {
        if (!(await confirmAction({ title: `Remove ${row.barker_name}'s ₱${row.amount}?`, danger: true, confirmText: 'Remove' }))) return;
        try {
            await conductor.removeDispatch(trip.id, row.id);
            reload();
            onChange();
        } catch (err) {
            notifyError(err);
        }
    };

    return (
        <div className="card mt-3">
            <div className="card-header bg-white d-flex justify-content-between">
                <span className="fw-semibold">Barker dispatch ({rows.length})</span>
                <span className="fare-amount fw-semibold">−{peso(total)}</span>
            </div>
            <div className="card-body">
                {canIssue && (
                    <form onSubmit={add} className="row g-2 align-items-end mb-3">
                        <div className="col-sm-6">
                            <label className="form-label mb-1">Barker name</label>
                            <input className="form-control form-control-sm" required maxLength={150}
                                value={form.barker_name} onChange={(e) => setForm({ ...form, barker_name: e.target.value })} />
                        </div>
                        <div className="col-sm-3">
                            <label className="form-label mb-1">Amount (₱)</label>
                            <input type="number" step="0.01" min="0.01" className="form-control form-control-sm" required
                                value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
                        </div>
                        <div className="col-sm-3">
                            <button className="btn btn-sm btn-outline-primary w-100" disabled={busy}>
                                {busy ? <span className="spinner-border spinner-border-sm" /> : <><i className="bi bi-plus-lg me-1" />Add</>}
                            </button>
                        </div>
                    </form>
                )}
                <table className="table table-sm mb-0">
                    <thead><tr><th>Barker</th><th className="text-end">Amount</th><th /></tr></thead>
                    <tbody>
                        {rows.length === 0 && <tr><td colSpan={3} className="tf-empty">No dispatches recorded.</td></tr>}
                        {rows.map((r) => (
                            <tr key={r.id}>
                                <td>{r.barker_name}</td>
                                <td className="text-end fare-amount">{peso(r.amount)}</td>
                                <td className="text-end text-nowrap">
                                    <button className="btn btn-sm btn-link p-0 me-2" title="Print dispatch receipt"
                                        onClick={() => openReceipt({ src: 'dispatch', id: r.id })}>
                                        <i className="bi bi-printer" />
                                    </button>
                                    {canIssue && (
                                        <button className="btn btn-sm btn-link text-danger p-0" onClick={() => remove(r)}>
                                            <i className="bi bi-trash" />
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

/* ---------- Attendance (clock in / out) ---------- */
function AttendanceBar({ onChange }) {
    const [open, setOpen] = useState(undefined); // undefined = loading
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => conductor.attendance().then((r) => setOpen(r.open ?? null)).catch(() => setOpen(null)), []);
    useEffect(() => { load(); }, [load]);

    const toggle = async () => {
        setBusy(true);
        try {
            const r = await conductor.toggleAttendance();
            notifySuccess(r.clocked_in ? 'Clocked in — have a safe trip.' : 'Clocked out.');
            await load();
            onChange?.();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    if (open === undefined) return null;

    const since = open ? new Date(open.clock_in_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : null;

    return (
        <div className={`card mb-3 border-${open ? 'success' : 'warning'}`}>
            <div className="card-body d-flex flex-wrap align-items-center gap-3">
                <i className={`bi ${open ? 'bi-clock-fill text-success' : 'bi-clock-history text-warning'} fs-4`} />
                <div className="me-auto">
                    <div className="fw-semibold">{open ? 'On shift' : 'Not clocked in'}</div>
                    <div className="small text-muted">
                        {open ? `Clocked in at ${since}` : 'Clock in to start a trip.'}
                    </div>
                </div>
                <button className={`btn btn-${open ? 'outline-secondary' : 'accent'}`} disabled={busy} onClick={toggle}>
                    {busy ? <span className="spinner-border spinner-border-sm me-2" /> : <i className={`bi ${open ? 'bi-box-arrow-right' : 'bi-box-arrow-in-right'} me-1`} />}
                    {open ? 'Clock out' : 'Clock in'}
                </button>
            </div>
        </div>
    );
}

/* ---------- Page ---------- */
export default function ConductorTrip() {
    const { user, can } = useAuth();
    const [trip, setTrip] = useState(undefined); // undefined = loading, null = none

    const load = useCallback(() => {
        conductor.activeTrip().then((t) => setTrip(t ?? null)).catch(() => setTrip(null));
    }, []);
    useEffect(load, [load]);

    const markOnTrip = async () => {
        try { await conductor.markOnTrip(); notifySuccess('Bus has left the terminal.'); load(); }
        catch (e) { notifyError(e); }
    };

    const endTrip = async () => {
        const t = await conductor.activeTrip();
        const rem = await conductor.remittance(t.id).catch(() => null);
        const suggested = rem ? rem.suggested_remit : t.summary.collected;
        const dispatchLine = rem && rem.total_dispatch > 0
            ? `<div class="text-start small text-muted">Less dispatch: <b>−${peso(rem.total_dispatch)}</b></div>`
            : '';
        const { isConfirmed, value } = await Swal.fire({
            title: 'End trip & remit',
            html: `<div class="text-start small text-muted mb-1">Collected: <b>${peso(t.summary.collected)}</b></div>`
                + dispatchLine
                + `<div class="text-start small text-muted mb-2">Suggested remit: <b>${peso(suggested)}</b></div>`,
            input: 'number',
            inputAttributes: { step: '0.01', min: '0' },
            inputValue: suggested,
            inputLabel: 'Amount remitted',
            showCancelButton: true,
            confirmButtonText: 'End trip',
        });
        if (!isConfirmed) return;
        try {
            const done = await conductor.endTrip({ remitted_amount: value });
            notifySuccess(`Trip ended. Balance ${peso(done.summary.balance)}.`);
            const { isConfirmed: wantsPrint } = await Swal.fire({
                title: 'Trip closed',
                text: 'Print the remittance receipt now?',
                icon: 'success',
                showCancelButton: true, confirmButtonText: 'Print receipt', cancelButtonText: 'Not now',
            });
            if (wantsPrint) openReceipt({ src: 'conductor', kind: 'remittance', id: done.id });
            load();
        } catch (e) { notifyError(e); }
    };

    const cancelTrip = async () => {
        const { isConfirmed, value } = await Swal.fire({
            title: 'Cancel trip?', input: 'text', inputLabel: 'Reason', inputPlaceholder: 'e.g. bus breakdown',
            showCancelButton: true, confirmButtonText: 'Cancel trip', customClass: { confirmButton: 'btn btn-danger px-4', cancelButton: 'btn btn-light px-4' },
            inputValidator: (v) => (!v ? 'A reason is required' : undefined),
        });
        if (!isConfirmed) return;
        try { await conductor.cancelTrip(value); notifySuccess('Trip cancelled.'); load(); }
        catch (e) { notifyError(e); }
    };

    if (trip === undefined) {
        return <div className="d-flex justify-content-center py-5"><span className="spinner-border text-primary" /></div>;
    }

    return (
        <>
            <PageHeader
                eyebrow="Conductor"
                title={trip ? 'Current trip' : 'My trips'}
                subtitle={trip?.reference ? `${trip.reference} · ${user?.name}` : `Signed in as ${user?.name}`}
                actions={(
                    <>
                        <button className="btn btn-outline-secondary" onClick={() => openReceipt({ src: 'shift' })}>
                            <i className="bi bi-receipt me-1" /> Shift summary
                        </button>
                        {trip && (
                            <>
                                <button className="btn btn-outline-secondary" onClick={() => openReceipt({ src: 'conductor', kind: 'departure', id: trip.id })}>
                                    <i className="bi bi-printer me-1" /> {trip.status === 'Departure' ? 'Departure slip' : 'Terminal slip'}
                                </button>
                                {trip.status === 'Departure' && (
                                    <button className="btn btn-accent" onClick={markOnTrip}><i className="bi bi-truck me-1" /> Mark On-Trip</button>
                                )}
                                <button className="btn btn-primary" onClick={endTrip}><i className="bi bi-flag-fill me-1" /> End trip</button>
                                <button className="btn btn-outline-danger" onClick={cancelTrip}>Cancel</button>
                            </>
                        )}
                    </>
                )}
            />

            {!trip && <AttendanceBar onChange={load} />}

            {!trip ? (
                <StartTripForm onStarted={load} />
            ) : (
                <>
                    <div className="card mb-3">
                        <div className="card-body d-flex flex-wrap gap-4 align-items-center">
                            <div>
                                <div className="tf-stat-label">Status</div>
                                <StatusBadge value={trip.status.toLowerCase()} />
                            </div>
                            <div><div className="tf-stat-label">Trip ref</div><div className="fw-semibold font-monospace small">{trip.reference ?? `#${trip.id}`}</div></div>
                            <div><div className="tf-stat-label">Bus</div><div className="fw-semibold">{trip.bus_number}</div></div>
                            <div><div className="tf-stat-label">Driver</div><div className="fw-semibold">{trip.driver?.name ?? '—'}</div></div>
                            <div><div className="tf-stat-label">Coverage</div><div className="fw-semibold">{trip.coverage_origin} → {trip.coverage_destination}</div></div>
                            <div className="ms-auto">
                                <div className="tf-stat-label">Collected</div>
                                <div className="fare-amount fs-5 fw-semibold">{peso(trip.summary.collected)}</div>
                            </div>
                        </div>
                    </div>
                    <Ticketing trip={trip} onChange={load} />
                    {can('dispatch.view') && (
                        <Dispatches trip={trip} canIssue={can('dispatch.issue')} onChange={load} />
                    )}
                </>
            )}
        </>
    );
}
