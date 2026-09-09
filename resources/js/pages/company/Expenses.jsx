import { useCallback, useEffect, useMemo, useState } from 'react';
import { swal as Swal } from '../../lib/ui.js';

import { useAuth } from '../../auth/AuthContext.jsx';
import DataTable from '../../components/DataTable.jsx';
import DenominationTable from '../../components/DenominationTable.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import StatusBadge from '../../components/StatusBadge.jsx';
import { buses as busesApi, expenses } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';
import { notifyError, notifySuccess } from '../../lib/ui.js';

const DENOMS = [1000, 500, 200, 100, 50, 20, 10, 5, 1];
const CATEGORIES = ['Fuel', 'Toll', 'Repair', 'Parts', 'Meal', 'Parking', 'Allowance', 'Other'];
const peso = (n) => `₱${Number(n ?? 0).toLocaleString()}`;
const today = () => new Date().toISOString().slice(0, 10);
const blank = () => ({
    bus_id: '', op_date: today(), shift: 'Morning', category: 'Fuel', description: '',
    ...Object.fromEntries(DENOMS.map((d) => [`q${d}`, ''])),
});

export default function Expenses() {
    const { can } = useAuth();
    const [buses, setBuses] = useState([]);
    const [filters, setFilters] = useState({ date: '', status: '', category: '' });
    const [form, setForm] = useState(null);
    const [saving, setSaving] = useState(false);

    const fetcher = useCallback(
        (params) => expenses.list({
            ...params,
            ...(filters.date ? { date: filters.date } : {}),
            ...(filters.status ? { status: filters.status } : {}),
            ...(filters.category ? { category: filters.category } : {}),
        }),
        [filters],
    );
    const { rows, meta, loading, page, setPage, reload } = useList(fetcher, { deps: [filters] });

    useEffect(() => {
        busesApi.list({ per_page: 200 }).then((r) => setBuses(r.data)).catch(() => {});
    }, []);

    const total = useMemo(
        () => (form ? DENOMS.reduce((s, d) => s + d * (Number(form[`q${d}`]) || 0), 0) : 0),
        [form],
    );

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const payload = {
                bus_id: Number(form.bus_id), op_date: form.op_date, shift: form.shift,
                category: form.category, description: form.description,
            };
            DENOMS.forEach((d) => { payload[`q${d}`] = Number(form[`q${d}`]) || 0; });
            await expenses.create(payload);
            notifySuccess(`Expense recorded — ${peso(total)}.`);
            setForm(null);
            reload();
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const voidExpense = async (row) => {
        const { isConfirmed, value } = await Swal.fire({
            title: `Void expense #${row.id}?`,
            html: `<div class="text-start small text-muted mb-2">${row.category} · ${row.bus?.bus_number} · ${peso(row.amount)} — the cash goes back to the drawer.</div>`
                + '<input id="reason" class="swal2-input" placeholder="Reason (required)">'
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
        try {
            await expenses.void(row.id, value);
            notifySuccess('Expense voided — cash restored.');
            reload();
        } catch (err) {
            notifyError(err);
        }
    };

    const columns = useMemo(() => [
        {
            key: 'exp', header: 'Expense',
            render: (r) => (
                <div>
                    <div className="fw-semibold">{r.category} — {r.description}</div>
                    <div className="small text-muted">{r.bus?.bus_number ?? `#${r.bus_id}`} · {r.op_date} · {r.shift} · by {r.recorded_by}</div>
                </div>
            ),
        },
        { key: 'amount', header: 'Amount', className: 'text-end fare-amount', render: (r) => peso(r.amount) },
        { key: 'status', header: 'Status', render: (r) => <StatusBadge value={r.status.toLowerCase()} /> },
        {
            key: 'note', header: '',
            render: (r) => (r.status === 'Voided'
                ? <span className="small text-muted" title={r.void_reason}>voided by {r.voided_by}</span> : null),
        },
        {
            key: 'actions', header: '', className: 'text-end',
            render: (r) => (r.status === 'Active' && can('expenses.void') && (
                <button className="btn btn-sm btn-outline-danger" onClick={() => voidExpense(r)}>
                    <i className="bi bi-x-octagon me-1" /> Void
                </button>
            )),
        },
    ], [can]);

    return (
        <>
            <PageHeader
                eyebrow="Operations"
                title="Expenses"
                subtitle="Cash paid out of a bus's takings — netted into that bus/day/shift cash rollup."
                actions={can('expenses.create') && (
                    <button className="btn btn-accent" onClick={() => setForm(blank())}>
                        <i className="bi bi-plus-lg me-1" /> Record expense
                    </button>
                )}
            />

            <div className="d-flex flex-wrap gap-2 mb-3 align-items-end">
                <div>
                    <label className="form-label mb-1 small">Date</label>
                    <input type="date" className="form-control form-control-sm" value={filters.date}
                        onChange={(e) => setFilters((f) => ({ ...f, date: e.target.value }))} />
                </div>
                <div>
                    <label className="form-label mb-1 small">Category</label>
                    <select className="form-select form-select-sm" value={filters.category}
                        onChange={(e) => setFilters((f) => ({ ...f, category: e.target.value }))}>
                        <option value="">All</option>
                        {CATEGORIES.map((c) => <option key={c}>{c}</option>)}
                    </select>
                </div>
                <div>
                    <label className="form-label mb-1 small">Status</label>
                    <select className="form-select form-select-sm" value={filters.status}
                        onChange={(e) => setFilters((f) => ({ ...f, status: e.target.value }))}>
                        <option value="">All</option>
                        <option>Active</option>
                        <option>Voided</option>
                    </select>
                </div>
                {(filters.date || filters.status || filters.category) && (
                    <button className="btn btn-sm btn-link" onClick={() => setFilters({ date: '', status: '', category: '' })}>Clear</button>
                )}
            </div>

            <DataTable columns={columns} rows={rows} loading={loading} empty="No expenses recorded." />
            <Pagination meta={meta} page={page} onPage={setPage} />

            <Modal
                open={!!form}
                title="Record expense"
                onClose={() => setForm(null)}
                footer={(
                    <>
                        <button className="btn btn-light" onClick={() => setForm(null)}>Cancel</button>
                        <button className="btn btn-primary" form="exp-form" disabled={saving}>
                            {saving && <span className="spinner-border spinner-border-sm me-2" />}Save · {peso(total)}
                        </button>
                    </>
                )}
            >
                {form && (
                    <form id="exp-form" onSubmit={save} className="vstack gap-3">
                        <div className="row g-2">
                            <div className="col-sm-4">
                                <label className="form-label">Bus *</label>
                                <select className="form-select" required value={form.bus_id}
                                    onChange={(e) => setForm({ ...form, bus_id: e.target.value })}>
                                    <option value="">Select…</option>
                                    {buses.map((b) => <option key={b.id} value={b.id}>{b.bus_number}</option>)}
                                </select>
                            </div>
                            <div className="col-sm-4">
                                <label className="form-label">Date *</label>
                                <input type="date" className="form-control" required max={today()}
                                    value={form.op_date} onChange={(e) => setForm({ ...form, op_date: e.target.value })} />
                            </div>
                            <div className="col-sm-4">
                                <label className="form-label">Shift *</label>
                                <select className="form-select" value={form.shift}
                                    onChange={(e) => setForm({ ...form, shift: e.target.value })}>
                                    <option>Morning</option>
                                    <option>Evening</option>
                                </select>
                            </div>
                            <div className="col-sm-4">
                                <label className="form-label">Category *</label>
                                <select className="form-select" value={form.category}
                                    onChange={(e) => setForm({ ...form, category: e.target.value })}>
                                    {CATEGORIES.map((c) => <option key={c}>{c}</option>)}
                                </select>
                            </div>
                            <div className="col-sm-8">
                                <label className="form-label">Description *</label>
                                <input className="form-control" required maxLength={255}
                                    value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
                            </div>
                        </div>

                        <div>
                            <label className="form-label mb-1">Cash paid out</label>
                            <DenominationTable
                                value={Object.fromEntries(DENOMS.map((d) => [`q${d}`, form[`q${d}`]]))}
                                onChange={(next) => setForm({ ...form, ...next })}
                            />
                        </div>
                    </form>
                )}
            </Modal>
        </>
    );
}
