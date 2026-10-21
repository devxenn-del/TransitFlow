import { useCallback, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';

import { useAuth } from '../../auth/AuthContext.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import { SkeletonText } from '../../components/Skeleton.jsx';
import { charters } from '../../lib/api.js';
import { useCompanyPath } from '../../lib/companyScope.jsx';
import { confirmAction, notifyError, notifySuccess, swal as Swal } from '../../lib/ui.js';
import CharterFormModal from './CharterFormModal.jsx';
import { CharterStatus, dt, peso } from './charterUi.jsx';

const PAYMENT_TYPES = ['Deposit', 'Payment', 'Refund'];
const PAYMENT_METHODS = ['Cash', 'Bank', 'E-Wallet', 'Other'];
const CHARGE_CATEGORIES = ['Overtime', 'Extra Distance', 'Waiting Time', 'Other'];
const EXPENSE_CATEGORIES = ['Fuel', 'Toll', 'Parking', 'Driver Allowance', 'Conductor Allowance', 'Meals', 'Other'];
const MONEY_OPEN = ['Pending', 'Approved', 'InProgress', 'Completed', 'Cancelled'];

const BLANK_PAYMENT = { type: 'Deposit', amount: '', method: 'Cash', reference_no: '', notes: '' };
const BLANK_CHARGE = { kind: 'Expense', category: 'Fuel', description: '', amount: '' };

/**
 * One charter booking: details, crew, approval, the trip the conductor ran
 * it as (departed / arrived), and its money — payments ledger, extra
 * charges, actual expenses and final settlement (App\Support\CharterLedger).
 * None of this money is part of ticket sales or passenger remittances.
 */
export default function CharterDetail() {
    const { charterId } = useParams();
    const { can } = useAuth();
    const navigate = useNavigate();
    const companyPath = useCompanyPath();
    const [c, setC] = useState(null);
    const [editing, setEditing] = useState(false);
    const [payment, setPayment] = useState(BLANK_PAYMENT);
    const [charge, setCharge] = useState(BLANK_CHARGE);
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => charters.get(charterId).then(setC).catch((e) => {
        notifyError(e);
        navigate(companyPath('charters'));
    }), [charterId]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => { load(); }, [load]);

    const run = async (fn, success) => {
        setBusy(true);
        try {
            setC(await fn());
            if (success) notifySuccess(success);
            return true;
        } catch (e) {
            notifyError(e);
            return false;
        } finally {
            setBusy(false);
        }
    };

    const askReason = async (title, confirmText) => {
        const { isConfirmed, value } = await Swal.fire({
            title, input: 'text', inputLabel: 'Reason (required)', showCancelButton: true, confirmButtonText: confirmText,
            customClass: { confirmButton: 'btn btn-danger px-4', cancelButton: 'btn btn-light px-4' },
            inputValidator: (v) => (!v ? 'A reason is required' : undefined),
        });
        return isConfirmed ? value : null;
    };

    if (!c) {
        return <div className="card border-0 shadow-sm"><div className="card-body"><SkeletonText lines={10} /></div></div>;
    }

    const ledger = c.ledger || {};
    const editable = ['Pending', 'Approved'].includes(c.status);
    const moneyOpen = can('charters.payments') && MONEY_OPEN.includes(c.status);
    const charges = (c.charges || []).filter((x) => x.kind === 'Charge');
    const expenses = (c.charges || []).filter((x) => x.kind === 'Expense');

    const approve = async () => {
        if (!(await confirmAction({ title: `Approve ${c.reference}?`, text: `${c.conductor?.name} will see it on the app and can start it from 2 hours before departure.`, confirmText: 'Approve' }))) return;
        run(() => charters.approve(c.id), 'Booking approved.');
    };
    const reject = async () => {
        const reason = await askReason(`Reject ${c.reference}?`, 'Reject');
        if (reason) run(() => charters.reject(c.id, reason), 'Booking rejected.');
    };
    const cancel = async () => {
        const reason = await askReason(`Cancel ${c.reference}?`, 'Cancel booking');
        if (reason) run(() => charters.cancel(c.id, reason), 'Booking cancelled.');
    };
    const settle = async () => {
        if (!(await confirmAction({ title: `Settle ${c.reference}?`, text: 'This records the final settlement and locks the charter\'s money.', confirmText: 'Settle' }))) return;
        run(() => charters.settle(c.id), 'Charter settled.');
    };

    const addPayment = async (e) => {
        e.preventDefault();
        if (await run(() => charters.addPayment(c.id, { ...payment, reference_no: payment.reference_no || null, notes: payment.notes || null }), `${payment.type} recorded.`)) {
            setPayment(BLANK_PAYMENT);
        }
    };
    const addCharge = async (e) => {
        e.preventDefault();
        if (await run(() => charters.addCharge(c.id, { ...charge, description: charge.description || null }), `${charge.kind === 'Charge' ? 'Charge' : 'Expense'} recorded.`)) {
            setCharge({ ...BLANK_CHARGE, kind: charge.kind, category: charge.kind === 'Charge' ? 'Overtime' : 'Fuel' });
        }
    };
    const removePayment = async (p) => {
        if (await confirmAction({ title: `Delete this ${p.type.toLowerCase()} of ${peso(p.amount)}?`, danger: true, confirmText: 'Delete' })) {
            run(() => charters.removePayment(c.id, p.id), 'Deleted.');
        }
    };
    const removeCharge = async (x) => {
        if (await confirmAction({ title: `Delete ${x.category} (${peso(x.amount)})?`, danger: true, confirmText: 'Delete' })) {
            run(() => charters.removeCharge(c.id, x.id), 'Deleted.');
        }
    };

    const steps = [
        ['Booked', c.created_at],
        ['Approved', c.approved_at],
        ['Started', c.trip?.started_at],
        ['Departed', c.trip?.departed_at],
        ['Arrived', c.trip?.arrived_at],
        ['Settled', c.settled_at],
    ];

    return (
        <>
            <PageHeader
                title={<>{c.reference} <CharterStatus status={c.status} /></>}
                subtitle={`${c.client_name} · ${c.pickup_point} → ${c.destination}`}
                actions={(
                    <>
                        <button className="btn btn-light" onClick={() => navigate(companyPath('charters'))}><i className="bi bi-arrow-left me-1" />Back</button>
                        {can('charters.edit') && editable && <button className="btn btn-outline-secondary" disabled={busy} onClick={() => setEditing(true)}><i className="bi bi-pencil me-1" />Edit</button>}
                        {can('charters.approve') && c.status === 'Pending' && <button className="btn btn-outline-danger" disabled={busy} onClick={reject}>Reject</button>}
                        {can('charters.approve') && c.status === 'Pending' && <button className="btn btn-primary" disabled={busy} onClick={approve}><i className="bi bi-check2-circle me-1" />Approve</button>}
                        {can('charters.edit') && editable && <button className="btn btn-outline-danger" disabled={busy} onClick={cancel}>Cancel booking</button>}
                        {can('charters.payments') && ledger.settleable && <button className="btn btn-success" disabled={busy} onClick={settle}><i className="bi bi-patch-check me-1" />Settle</button>}
                    </>
                )}
            />

            {c.status === 'Rejected' && <div className="alert alert-danger">Rejected: {c.rejected_reason}</div>}
            {c.status === 'Cancelled' && <div className="alert alert-secondary">Cancelled: {c.cancelled_reason}</div>}

            {/* Progress — the trip the conductor ran it as. */}
            <div className="card border-0 shadow-sm mb-3">
                <div className="card-body d-flex flex-wrap gap-4">
                    {steps.map(([label, at]) => (
                        <div key={label} className={at ? '' : 'text-body-secondary opacity-50'}>
                            <div className="small fw-semibold">
                                <i className={`bi ${at ? 'bi-check-circle-fill text-success' : 'bi-circle'} me-1`} />{label}
                            </div>
                            <div className="small">{at ? dt(at) : '—'}</div>
                        </div>
                    ))}
                    {c.trip && <div className="ms-auto small text-body-secondary align-self-center">Trip {c.trip.reference}</div>}
                </div>
            </div>

            <div className="row g-3">
                <div className="col-lg-5">
                    <div className="card border-0 shadow-sm h-100">
                        <div className="card-body">
                            <h6 className="tf-eyebrow">Client</h6>
                            <Row label="Client / organization" value={c.client_name} />
                            <Row label="Contact person" value={c.contact_person} />
                            <Row label="Contact number" value={c.contact_number} />
                            <Row label="Email" value={c.contact_email} />
                            <Row label="Purpose" value={c.purpose} />

                            <h6 className="tf-eyebrow mt-3">Trip</h6>
                            <Row label="Pickup point" value={c.pickup_point} />
                            <Row label="Destination" value={c.destination} />
                            <Row label="Departure" value={dt(c.departure_at)} />
                            <Row label="Estimated return" value={dt(c.return_at)} />
                            <Row label="Passengers" value={c.passenger_count} />

                            <h6 className="tf-eyebrow mt-3">Crew</h6>
                            <Row label="Bus" value={c.bus ? `${c.bus.bus_number} (${c.bus.plate_number})` : null} />
                            <Row label="Driver" value={c.driver?.name} />
                            <Row label="Conductor" value={c.conductor?.name} />

                            <h6 className="tf-eyebrow mt-3">Booking</h6>
                            <Row label="Created by" value={c.created_by} />
                            <Row label="Approved by" value={c.approved_by} />
                            <Row label="Settled by" value={c.settled_by} />
                            <Row label="Notes" value={c.notes} />
                        </div>
                    </div>
                </div>

                <div className="col-lg-7">
                    <div className="card border-0 shadow-sm mb-3">
                        <div className="card-body">
                            <h6 className="tf-eyebrow">Finances <span className="text-body-secondary fw-normal">— separate from ticket sales &amp; remittances</span></h6>
                            <div className="row g-2 text-center">
                                <Tile label="Agreed price" value={peso(ledger.agreed_price)} />
                                <Tile label="+ Charges" value={peso(ledger.charges)} />
                                <Tile label="Amount due" value={peso(ledger.amount_due)} strong />
                                <Tile label="Paid" value={peso(ledger.amount_paid)} hint={`Deposits ${peso(ledger.deposits)} · Payments ${peso(ledger.payments)}${ledger.refunds ? ` · Refunds ${peso(ledger.refunds)}` : ''}`} />
                                <Tile label="Balance" value={peso(ledger.balance)} strong tone={ledger.balance > 0 ? 'danger' : ledger.balance < 0 ? 'warning' : 'success'} />
                                <Tile label="Expenses" value={peso(ledger.expenses)} />
                                <Tile label="Net income" value={peso(ledger.net_income)} strong />
                            </div>
                            {c.status === 'Completed' && !ledger.settleable && (
                                <div className="small text-body-secondary mt-2">
                                    {ledger.balance > 0 ? `Collect the remaining ${peso(ledger.balance)} to settle.` : `Overpaid by ${peso(-ledger.balance)} — record a refund to settle.`}
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Payments ledger */}
                    <div className="card border-0 shadow-sm mb-3">
                        <div className="card-body">
                            <h6 className="tf-eyebrow">Payments</h6>
                            <MoneyTable
                                rows={c.payments || []}
                                empty="No payments yet."
                                columns={(p) => [dt(p.paid_at), <span key="t" className={p.type === 'Refund' ? 'text-danger' : ''}>{p.type}</span>, p.method, p.reference_no || '—', p.recorded_by || '—']}
                                headers={['Date', 'Type', 'Method', 'Ref #', 'By']}
                                amount={(p) => (p.type === 'Refund' ? `−${peso(p.amount)}` : peso(p.amount))}
                                onRemove={moneyOpen ? removePayment : null}
                            />
                            {moneyOpen && (
                                <form onSubmit={addPayment} className="row g-2 align-items-end mt-1">
                                    <div className="col-md-2">
                                        <select className="form-select form-select-sm" value={payment.type} onChange={(e) => setPayment({ ...payment, type: e.target.value })}>
                                            {PAYMENT_TYPES.map((t) => <option key={t}>{t}</option>)}
                                        </select>
                                    </div>
                                    <div className="col-md-2">
                                        <input type="number" min="0.01" step="0.01" required className="form-control form-control-sm" placeholder="Amount" value={payment.amount} onChange={(e) => setPayment({ ...payment, amount: e.target.value })} />
                                    </div>
                                    <div className="col-md-2">
                                        <select className="form-select form-select-sm" value={payment.method} onChange={(e) => setPayment({ ...payment, method: e.target.value })}>
                                            {PAYMENT_METHODS.map((m) => <option key={m}>{m}</option>)}
                                        </select>
                                    </div>
                                    <div className="col-md-3">
                                        <input className="form-control form-control-sm" maxLength={100} placeholder="Ref # (OR / bank / e-wallet)" value={payment.reference_no} onChange={(e) => setPayment({ ...payment, reference_no: e.target.value })} />
                                    </div>
                                    <div className="col-md-3">
                                        <button className="btn btn-sm btn-primary w-100" disabled={busy}><i className="bi bi-plus-lg me-1" />Record {payment.type.toLowerCase()}</button>
                                    </div>
                                </form>
                            )}
                        </div>
                    </div>

                    {/* Additional charges + actual expenses */}
                    <div className="card border-0 shadow-sm">
                        <div className="card-body">
                            <h6 className="tf-eyebrow">Additional charges <span className="text-body-secondary fw-normal">— billed to the client</span></h6>
                            <MoneyTable
                                rows={charges}
                                empty="No additional charges."
                                headers={['Date', 'Category', 'Description', 'By']}
                                columns={(x) => [dt(x.incurred_at), x.category, x.description || '—', x.recorded_by || '—']}
                                amount={(x) => peso(x.amount)}
                                onRemove={moneyOpen ? removeCharge : null}
                            />
                            <h6 className="tf-eyebrow mt-3">Actual expenses <span className="text-body-secondary fw-normal">— the company's cost of running it</span></h6>
                            <MoneyTable
                                rows={expenses}
                                empty="No expenses recorded."
                                headers={['Date', 'Category', 'Description', 'By']}
                                columns={(x) => [dt(x.incurred_at), x.category, x.description || '—', x.recorded_by || '—']}
                                amount={(x) => peso(x.amount)}
                                onRemove={moneyOpen ? removeCharge : null}
                            />
                            {moneyOpen && (
                                <form onSubmit={addCharge} className="row g-2 align-items-end mt-1">
                                    <div className="col-md-2">
                                        <select className="form-select form-select-sm" value={charge.kind} onChange={(e) => setCharge({ ...charge, kind: e.target.value, category: e.target.value === 'Charge' ? 'Overtime' : 'Fuel' })}>
                                            <option value="Expense">Expense</option>
                                            <option value="Charge">Charge</option>
                                        </select>
                                    </div>
                                    <div className="col-md-3">
                                        <input className="form-control form-control-sm" list="charter-categories" required maxLength={50} placeholder="Category" value={charge.category} onChange={(e) => setCharge({ ...charge, category: e.target.value })} />
                                        <datalist id="charter-categories">
                                            {(charge.kind === 'Charge' ? CHARGE_CATEGORIES : EXPENSE_CATEGORIES).map((x) => <option key={x} value={x} />)}
                                        </datalist>
                                    </div>
                                    <div className="col-md-3">
                                        <input className="form-control form-control-sm" maxLength={255} placeholder="Description" value={charge.description} onChange={(e) => setCharge({ ...charge, description: e.target.value })} />
                                    </div>
                                    <div className="col-md-2">
                                        <input type="number" min="0.01" step="0.01" required className="form-control form-control-sm" placeholder="Amount" value={charge.amount} onChange={(e) => setCharge({ ...charge, amount: e.target.value })} />
                                    </div>
                                    <div className="col-md-2">
                                        <button className="btn btn-sm btn-primary w-100" disabled={busy}><i className="bi bi-plus-lg me-1" />Add</button>
                                    </div>
                                </form>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <CharterFormModal
                open={editing}
                booking={c}
                onClose={() => setEditing(false)}
                onSaved={(saved) => { setEditing(false); setC(saved); }}
            />
        </>
    );
}

function Row({ label, value }) {
    return (
        <div className="d-flex justify-content-between gap-3 py-1 border-bottom small">
            <span className="text-body-secondary">{label}</span>
            <span className="text-end fw-semibold">{value || value === 0 ? value : '—'}</span>
        </div>
    );
}

function Tile({ label, value, hint, strong, tone }) {
    return (
        <div className="col-6 col-md-3">
            <div className="border rounded p-2 h-100" title={hint || ''}>
                <div className="small text-body-secondary">{label}</div>
                <div className={`${strong ? 'fw-bold fs-6' : 'fw-semibold'} ${tone ? `text-${tone}` : ''}`}>{value}</div>
            </div>
        </div>
    );
}

function MoneyTable({ rows, empty, headers, columns, amount, onRemove }) {
    return (
        <div className="table-responsive">
            <table className="table table-sm align-middle mb-1">
                <thead className="table-light">
                    <tr>
                        {headers.map((h) => <th key={h} className="small">{h}</th>)}
                        <th className="small text-end">Amount</th>
                        {onRemove && <th />}
                    </tr>
                </thead>
                <tbody>
                    {rows.length === 0 && <tr><td colSpan={headers.length + 2} className="small text-body-secondary">{empty}</td></tr>}
                    {rows.map((r) => (
                        <tr key={r.id}>
                            {columns(r).map((v, i) => <td key={i} className="small">{v}</td>)}
                            <td className="small text-end text-nowrap fw-semibold">{amount(r)}</td>
                            {onRemove && (
                                <td className="text-end">
                                    <button type="button" className="btn btn-sm btn-link text-danger p-0" title="Delete" onClick={() => onRemove(r)}>
                                        <i className="bi bi-trash" />
                                    </button>
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
