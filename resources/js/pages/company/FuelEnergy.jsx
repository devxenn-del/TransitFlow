import { useCallback, useEffect, useMemo, useState } from 'react';
import { swal as Swal } from '../../lib/ui.js';

import { useAuth } from '../../auth/AuthContext.jsx';
import DataTable from '../../components/DataTable.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import StatusBadge from '../../components/StatusBadge.jsx';
import { buses as busesApi, charging, fuel } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

const peso = (n) => `₱${Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
const dt = (v) => (v ? new Date(v).toLocaleString() : '—');
const FUEL_TYPES = ['Diesel', 'Gasoline'];
const blankFuel = () => ({ bus_id: '', fuel_type: 'Diesel', liters: '', price_per_liter: '', odometer: '', station: '', notes: '' });

/* ---------------- Fuel tab ---------------- */
function FuelTab({ buses }) {
    const { can } = useAuth();
    const { rows, meta, loading, page, setPage, reload } = useList(fuel.list);
    const [form, setForm] = useState(null);
    const [saving, setSaving] = useState(false);

    const amount = useMemo(
        () => (form ? Math.round((Number(form.liters) || 0) * (Number(form.price_per_liter) || 0) * 100) / 100 : 0),
        [form],
    );

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            await fuel.create({
                bus_id: Number(form.bus_id),
                fuel_type: form.fuel_type,
                liters: Number(form.liters),
                price_per_liter: Number(form.price_per_liter),
                odometer: form.odometer ? Number(form.odometer) : null,
                station: form.station || null,
                notes: form.notes || null,
            });
            notifySuccess(`Fuel recorded — ${peso(amount)}.`);
            setForm(null);
            reload();
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const remove = async (r) => {
        if (!(await confirmAction({ title: `Delete this ${r.fuel_type} record?`, danger: true, confirmText: 'Delete' }))) return;
        try { await fuel.remove(r.id); notifySuccess('Fuel record deleted.'); reload(); }
        catch (err) { notifyError(err); }
    };

    const columns = [
        {
            key: 'when', header: 'Fueled',
            render: (r) => <div><div className="fw-semibold">{dt(r.fueled_at)}</div><div className="small text-muted">{r.bus?.bus_number} · by {r.recorded_by}</div></div>,
        },
        { key: 'type', header: 'Type', render: (r) => r.fuel_type },
        { key: 'liters', header: 'Liters', className: 'text-end', render: (r) => Number(r.liters).toFixed(2) },
        { key: 'ppl', header: '₱/L', className: 'text-end', render: (r) => Number(r.price_per_liter).toFixed(2) },
        { key: 'amount', header: 'Amount', className: 'text-end fare-amount', render: (r) => peso(r.amount_paid) },
        { key: 'station', header: 'Station', render: (r) => r.station || <span className="text-muted">—</span> },
        {
            key: 'actions', header: '', className: 'text-end',
            render: (r) => can('fuel.delete') && (
                <button className="btn btn-sm btn-outline-danger" onClick={() => remove(r)}><i className="bi bi-trash" /></button>
            ),
        },
    ];

    return (
        <>
            {can('fuel.record') && (
                <div className="mb-3 text-end">
                    <button className="btn btn-accent" onClick={() => setForm(blankFuel())}>
                        <i className="bi bi-plus-lg me-1" /> Record fuel
                    </button>
                </div>
            )}
            <DataTable columns={columns} rows={rows} loading={loading} empty="No fuel records yet." />
            <Pagination meta={meta} page={page} onPage={setPage} />

            <Modal
                open={!!form}
                title="Record fuel purchase"
                onClose={() => setForm(null)}
                footer={(
                    <>
                        <button className="btn btn-light" onClick={() => setForm(null)}>Cancel</button>
                        <button className="btn btn-primary" form="fuel-form" disabled={saving}>
                            {saving && <span className="spinner-border spinner-border-sm me-2" />}Save · {peso(amount)}
                        </button>
                    </>
                )}
            >
                {form && (
                    <form id="fuel-form" onSubmit={save} className="vstack gap-3">
                        <div className="row g-2">
                            <div className="col-sm-7">
                                <label className="form-label">Bus *</label>
                                <select className="form-select" required value={form.bus_id}
                                    onChange={(e) => setForm({ ...form, bus_id: e.target.value })}>
                                    <option value="">Select…</option>
                                    {buses.map((b) => <option key={b.id} value={b.id}>{b.bus_number}</option>)}
                                </select>
                            </div>
                            <div className="col-sm-5">
                                <label className="form-label">Fuel type *</label>
                                <select className="form-select" value={form.fuel_type}
                                    onChange={(e) => setForm({ ...form, fuel_type: e.target.value })}>
                                    {FUEL_TYPES.map((t) => <option key={t}>{t}</option>)}
                                </select>
                            </div>
                            <div className="col-sm-4">
                                <label className="form-label">Liters *</label>
                                <input type="number" step="0.01" min="0" className="form-control" required
                                    value={form.liters} onChange={(e) => setForm({ ...form, liters: e.target.value })} />
                            </div>
                            <div className="col-sm-4">
                                <label className="form-label">Price / liter *</label>
                                <input type="number" step="0.01" min="0" className="form-control" required
                                    value={form.price_per_liter} onChange={(e) => setForm({ ...form, price_per_liter: e.target.value })} />
                            </div>
                            <div className="col-sm-4">
                                <label className="form-label">Odometer</label>
                                <input type="number" min="0" className="form-control"
                                    value={form.odometer} onChange={(e) => setForm({ ...form, odometer: e.target.value })} />
                            </div>
                            <div className="col-sm-7">
                                <label className="form-label">Station</label>
                                <input className="form-control" value={form.station}
                                    onChange={(e) => setForm({ ...form, station: e.target.value })} />
                            </div>
                            <div className="col-sm-5">
                                <label className="form-label">Notes</label>
                                <input className="form-control" value={form.notes}
                                    onChange={(e) => setForm({ ...form, notes: e.target.value })} />
                            </div>
                        </div>
                        <div className="d-flex justify-content-between border-top pt-2">
                            <span className="fw-semibold">Amount</span>
                            <span className="fw-semibold fare-amount fs-5">{peso(amount)}</span>
                        </div>
                    </form>
                )}
            </Modal>
        </>
    );
}

/* ---------------- EV Charging tab ---------------- */
function ChargingTab({ buses }) {
    const { can } = useAuth();
    const { rows, meta, loading, page, setPage, reload } = useList(charging.list);

    const start = async () => {
        const { isConfirmed, value } = await Swal.fire({
            title: 'Start charging',
            html: `<select id="bus" class="swal2-select">${buses.map((b) => `<option value="${b.id}">${b.bus_number}</option>`).join('')}</select>`
                + '<input id="pct" type="number" min="0" max="100" class="swal2-input" placeholder="Battery start %">'
                + '<input id="loc" class="swal2-input" placeholder="Location (optional)">',
            focusConfirm: false, showCancelButton: true, confirmButtonText: 'Start',
            preConfirm: () => {
                const bus_id = Number(document.getElementById('bus').value);
                const battery_start_pct = Number(document.getElementById('pct').value);
                if (!bus_id) return Swal.showValidationMessage('Pick a bus');
                if (!(battery_start_pct >= 0 && battery_start_pct <= 100)) return Swal.showValidationMessage('Battery % must be 0–100');
                return { bus_id, battery_start_pct, location: document.getElementById('loc').value || null };
            },
        });
        if (!isConfirmed) return;
        try { await charging.start(value); notifySuccess('Charging started.'); reload(); }
        catch (err) { notifyError(err); }
    };

    const end = async (r) => {
        const { isConfirmed, value } = await Swal.fire({
            title: `End charging — ${r.bus?.bus_number}`,
            html: `<div class="text-start small text-muted mb-2">Started at ${r.battery_start_pct}% · ${dt(r.started_at)}</div>`
                + '<input id="pct" type="number" min="0" max="100" class="swal2-input" placeholder="Battery end %">'
                + '<input id="notes" class="swal2-input" placeholder="Notes (optional)">',
            focusConfirm: false, showCancelButton: true, confirmButtonText: 'End session',
            preConfirm: () => {
                const battery_end_pct = Number(document.getElementById('pct').value);
                if (!(battery_end_pct >= 0 && battery_end_pct <= 100)) return Swal.showValidationMessage('Battery % must be 0–100');
                return { battery_end_pct, notes: document.getElementById('notes').value || null };
            },
        });
        if (!isConfirmed) return;
        try { await charging.end(r.id, value); notifySuccess('Charging session completed.'); reload(); }
        catch (err) { notifyError(err); }
    };

    const columns = [
        {
            key: 'bus', header: 'Bus',
            render: (r) => <div><div className="fw-semibold">{r.bus?.bus_number}</div><div className="small text-muted">by {r.started_by}{r.location ? ` · ${r.location}` : ''}</div></div>,
        },
        { key: 'status', header: 'Status', render: (r) => <StatusBadge value={r.status.toLowerCase()} /> },
        { key: 'start', header: 'Started', render: (r) => <span className="small">{dt(r.started_at)} · {r.battery_start_pct}%</span> },
        {
            key: 'end', header: 'Ended',
            render: (r) => (r.ended_at
                ? <span className="small">{dt(r.ended_at)} · {r.battery_end_pct}%</span>
                : <span className="text-muted small">—</span>),
        },
        {
            key: 'gain', header: 'Gain / time', className: 'text-end',
            render: (r) => (r.battery_gained_pct != null
                ? <span>+{r.battery_gained_pct}% · {r.duration_minutes}m</span>
                : <span className="text-muted small">{r.duration_minutes}m…</span>),
        },
        {
            key: 'actions', header: '', className: 'text-end',
            render: (r) => (r.status === 'Charging' && can('charging.record') && (
                <button className="btn btn-sm btn-primary" onClick={() => end(r)}>
                    <i className="bi bi-stop-circle me-1" /> End
                </button>
            )),
        },
    ];

    return (
        <>
            {can('charging.record') && (
                <div className="mb-3 text-end">
                    <button className="btn btn-accent" onClick={start}>
                        <i className="bi bi-lightning-charge me-1" /> Start charging
                    </button>
                </div>
            )}
            <DataTable columns={columns} rows={rows} loading={loading} empty="No charging sessions yet." />
            <Pagination meta={meta} page={page} onPage={setPage} />
        </>
    );
}

/* ---------------- Page ---------------- */
export default function FuelEnergy() {
    const { can } = useAuth();
    const [tab, setTab] = useState('fuel');
    const [buses, setBuses] = useState([]);

    const loadBuses = useCallback(() => busesApi.list({ per_page: 200 }).then((r) => setBuses(r.data)).catch(() => {}), []);
    useEffect(() => { loadBuses(); }, [loadBuses]);

    return (
        <>
            <PageHeader
                eyebrow="Operations"
                title="Fuel & Energy"
                subtitle="Fuel purchases and EV charging sessions per bus."
            />

            <ul className="nav nav-tabs mb-3">
                <li className="nav-item">
                    <button className={`nav-link${tab === 'fuel' ? ' active' : ''}`} onClick={() => setTab('fuel')}>
                        <i className="bi bi-fuel-pump me-1" /> Fuel
                    </button>
                </li>
                {can('charging.view') && (
                    <li className="nav-item">
                        <button className={`nav-link${tab === 'charging' ? ' active' : ''}`} onClick={() => setTab('charging')}>
                            <i className="bi bi-ev-station me-1" /> EV Charging
                        </button>
                    </li>
                )}
            </ul>

            {tab === 'fuel' ? <FuelTab buses={buses} /> : <ChargingTab buses={buses} />}
        </>
    );
}
