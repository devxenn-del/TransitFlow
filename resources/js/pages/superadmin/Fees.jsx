import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';

import { useAuth } from '../../auth/AuthContext.jsx';
import DataTable from '../../components/DataTable.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import StatusBadge from '../../components/StatusBadge.jsx';
import { companies as companiesApi, fees } from '../../lib/api.js';
import { BILLING_FREQUENCIES, RateBadge, feeAmount, fmtDate, todayIso } from '../../lib/billing.jsx';
import { useList } from '../../lib/useList.js';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

const BLANK = { name: '', description: '', amount: '', billing_frequency: 'monthly', billing_interval_months: '', is_active: true, effective_date: todayIso() };

/**
 * Fee Management (Super Admin): the platform fee catalogue with each fee's
 * standard price, and which companies it is billed to. Company-specific
 * special pricing is set per company, in its workspace → Pricing Configuration.
 */
export default function Fees() {
    const { can } = useAuth();
    const canManage = can('fees.manage');
    const { rows, loading, reload } = useList(fees.list);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(BLANK);
    const [saving, setSaving] = useState(false);
    const [assigning, setAssigning] = useState(null);

    const openNew = () => { setForm(BLANK); setEditing({}); };
    const openEdit = (f) => {
        setForm({
            name: f.name,
            description: f.description ?? '',
            amount: f.amount,
            billing_frequency: f.billing_frequency,
            billing_interval_months: f.billing_interval_months ?? '',
            is_active: f.is_active,
            effective_date: f.effective_date,
        });
        setEditing(f);
    };

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const payload = {
                ...form,
                description: form.description || null,
                billing_interval_months: form.billing_frequency === 'custom' ? Number(form.billing_interval_months) : null,
            };
            if (editing.id) {
                await fees.update(editing.id, payload);
                notifySuccess('Fee updated. Already-generated statements keep their amounts.');
            } else {
                await fees.create(payload);
                notifySuccess('Fee created.');
            }
            setEditing(null);
            reload();
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const toggle = async (f) => {
        const disabling = f.is_active;
        if (disabling && !(await confirmAction({
            title: `Disable ${f.name}?`,
            text: 'It will no longer be billed to any company until re-enabled.',
            confirmText: 'Disable',
            danger: true,
        }))) return;
        try {
            await fees.setStatus(f.id, !f.is_active);
            notifySuccess(`${f.name} ${disabling ? 'disabled' : 'enabled'}.`);
            reload();
        } catch (err) {
            notifyError(err);
        }
    };

    const remove = async (f) => {
        if (!(await confirmAction({
            title: `Delete ${f.name}?`,
            text: 'It stops being billed. Past billing statements keep their lines.',
            danger: true,
            confirmText: 'Delete',
        }))) return;
        try {
            await fees.remove(f.id);
            notifySuccess('Fee deleted.');
            reload();
        } catch (err) {
            notifyError(err);
        }
    };

    const columns = [
        {
            key: 'name',
            header: 'Fee',
            render: (f) => (
                <>
                    <div className="fw-semibold">{f.name}</div>
                    {f.description && <div className="small text-body-secondary">{f.description}</div>}
                </>
            ),
        },
        { key: 'amount', header: 'Standard rate', className: 'text-nowrap', render: (f) => <span className="fw-semibold">{feeAmount(f.amount)}</span> },
        { key: 'frequency', header: 'Billing', render: (f) => f.frequency_label },
        { key: 'effective_date', header: 'Effective', className: 'text-nowrap', render: (f) => fmtDate(f.effective_date) },
        {
            key: 'applies',
            header: 'Assigned to',
            render: (f) => (f.applies_to_all_companies
                ? <span className="badge text-bg-light border">All companies</span>
                : <span className="badge text-bg-light border">{f.assigned_companies_count} selected</span>),
        },
        {
            key: 'special',
            header: 'Special rates',
            render: (f) => (f.special_rates_count ? <span className="badge text-bg-warning-subtle text-warning-emphasis">{f.special_rates_count}</span> : <span className="text-muted">—</span>),
        },
        { key: 'status', header: 'Status', render: (f) => <StatusBadge value={f.is_active ? 'active' : 'inactive'} /> },
        {
            key: 'actions',
            header: '',
            className: 'text-end text-nowrap',
            render: (f) => (
                <>
                    <button className="btn btn-sm btn-outline-secondary me-1" title="Companies" onClick={() => setAssigning(f)}>
                        <i className="bi bi-buildings" />
                    </button>
                    {canManage && (
                        <>
                            <button className="btn btn-sm btn-outline-secondary me-1" title="Edit" onClick={() => openEdit(f)}>
                                <i className="bi bi-pencil" />
                            </button>
                            <button className="btn btn-sm btn-outline-secondary me-1" title={f.is_active ? 'Disable' : 'Enable'} onClick={() => toggle(f)}>
                                <i className={`bi ${f.is_active ? 'bi-pause-circle' : 'bi-play-circle'}`} />
                            </button>
                            <button className="btn btn-sm btn-outline-danger" title="Delete" onClick={() => remove(f)}>
                                <i className="bi bi-trash" />
                            </button>
                        </>
                    )}
                </>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Tenants"
                title="Fee Management"
                subtitle="System, rental and other platform fees, their standard rates, and which companies are billed. Special pricing is set per company under its Pricing Configuration."
                actions={canManage && (
                    <button className="btn btn-accent" onClick={openNew}>
                        <i className="bi bi-plus-lg me-1" /> Add fee
                    </button>
                )}
            />

            <DataTable columns={columns} rows={rows} loading={loading} empty="No fees configured yet." />

            <Modal
                open={!!editing}
                title={editing?.id ? `Edit ${editing.name}` : 'Add fee'}
                onClose={() => setEditing(null)}
                footer={
                    <>
                        <button className="btn btn-light" onClick={() => setEditing(null)}>Cancel</button>
                        <button className="btn btn-primary" form="fee-form" disabled={saving}>
                            {saving && <span className="spinner-border spinner-border-sm me-2" />}Save
                        </button>
                    </>
                }
            >
                <form id="fee-form" onSubmit={save} className="vstack gap-3">
                    <div>
                        <label className="form-label">Fee name *</label>
                        <input className="form-control" required maxLength={120} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                    </div>
                    <div>
                        <label className="form-label">Description</label>
                        <textarea className="form-control" rows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
                    </div>
                    <div className="row g-2">
                        <div className="col-md-6">
                            <label className="form-label">Standard amount (₱) *</label>
                            <input type="number" min="0" step="0.01" className="form-control" required value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Effective date *</label>
                            <input type="date" className="form-control" required value={form.effective_date} onChange={(e) => setForm({ ...form, effective_date: e.target.value })} />
                        </div>
                    </div>
                    <div className="row g-2">
                        <div className="col-md-6">
                            <label className="form-label">Billing frequency *</label>
                            <select className="form-select" value={form.billing_frequency} onChange={(e) => setForm({ ...form, billing_frequency: e.target.value })}>
                                {BILLING_FREQUENCIES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                            </select>
                        </div>
                        {form.billing_frequency === 'custom' && (
                            <div className="col-md-6">
                                <label className="form-label">Every (months) *</label>
                                <input type="number" min="1" max="120" className="form-control" required value={form.billing_interval_months} onChange={(e) => setForm({ ...form, billing_interval_months: e.target.value })} />
                            </div>
                        )}
                    </div>
                    <div className="form-check form-switch">
                        <input id="fee-active" type="checkbox" className="form-check-input" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
                        <label htmlFor="fee-active" className="form-check-label">Enabled (included in billing)</label>
                    </div>
                    {editing?.id && (
                        <div className="form-text">
                            Changing the amount affects future billing only — generated statements keep what was charged. Company special rates are not changed.
                        </div>
                    )}
                </form>
            </Modal>

            {assigning && <FeeCompaniesModal fee={assigning} canManage={canManage} onClose={() => setAssigning(null)} onSaved={reload} />}
        </>
    );
}

/**
 * Which companies a fee is billed to, with what each pays today, and (for
 * fees.manage) switching between "all companies" and a chosen set.
 */
function FeeCompaniesModal({ fee, canManage, onClose, onSaved }) {
    const [detail, setDetail] = useState(null);
    const [allCompanies, setAllCompanies] = useState([]);
    const [editingAssignments, setEditingAssignments] = useState(false);
    const [appliesToAll, setAppliesToAll] = useState(fee.applies_to_all_companies);
    const [selected, setSelected] = useState([]);
    const [saving, setSaving] = useState(false);

    const load = () => fees.get(fee.id).then((res) => {
        setDetail(res);
        setAppliesToAll(res.data.applies_to_all_companies);
        setSelected(res.data.applies_to_all_companies ? [] : res.companies.map((c) => c.id));
    }).catch(notifyError);

    useEffect(() => { load(); }, [fee.id]); // eslint-disable-line react-hooks/exhaustive-deps

    const startEditing = () => {
        companiesApi.list({ per_page: 500 }).then((res) => setAllCompanies(res.data ?? [])).catch(notifyError);
        setEditingAssignments(true);
    };

    const toggleCompany = (id) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));

    const saveAssignments = async () => {
        setSaving(true);
        try {
            await fees.setCompanies(fee.id, { applies_to_all_companies: appliesToAll, company_ids: appliesToAll ? [] : selected });
            notifySuccess('Assignments saved.');
            setEditingAssignments(false);
            onSaved();
            load();
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal
            open
            size="lg"
            title={`${fee.name} — companies`}
            onClose={onClose}
            footer={editingAssignments ? (
                <>
                    <button className="btn btn-light" onClick={() => setEditingAssignments(false)}>Cancel</button>
                    <button className="btn btn-primary" disabled={saving} onClick={saveAssignments}>
                        {saving && <span className="spinner-border spinner-border-sm me-2" />}Save assignments
                    </button>
                </>
            ) : (
                <>
                    {canManage && <button className="btn btn-outline-primary me-auto" onClick={startEditing}><i className="bi bi-pencil me-1" />Change assignments</button>}
                    <button className="btn btn-light" onClick={onClose}>Close</button>
                </>
            )}
        >
            {!detail && <div className="text-center py-4"><span className="spinner-border spinner-border-sm" /></div>}

            {detail && editingAssignments && (
                <div className="vstack gap-3">
                    <div>
                        <div className="form-check">
                            <input id="assign-all" type="radio" className="form-check-input" checked={appliesToAll} onChange={() => setAppliesToAll(true)} />
                            <label htmlFor="assign-all" className="form-check-label">All companies <span className="text-body-secondary small">(including companies added later)</span></label>
                        </div>
                        <div className="form-check">
                            <input id="assign-some" type="radio" className="form-check-input" checked={!appliesToAll} onChange={() => setAppliesToAll(false)} />
                            <label htmlFor="assign-some" className="form-check-label">Selected companies only</label>
                        </div>
                    </div>
                    {!appliesToAll && (
                        <div className="border rounded p-2" style={{ maxHeight: 320, overflowY: 'auto' }}>
                            {allCompanies.length === 0 && <div className="text-body-secondary small p-2">Loading companies…</div>}
                            {allCompanies.map((c) => (
                                <div key={c.id} className="form-check">
                                    <input id={`co-${c.id}`} type="checkbox" className="form-check-input" checked={selected.includes(c.id)} onChange={() => toggleCompany(c.id)} />
                                    <label htmlFor={`co-${c.id}`} className="form-check-label">{c.name} <code className="small">{c.code}</code></label>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}

            {detail && !editingAssignments && (
                <>
                    <p className="text-body-secondary small mb-2">
                        Standard rate {feeAmount(detail.data.amount)} · {detail.data.frequency_label} · {detail.data.applies_to_all_companies ? 'billed to every company' : 'billed to selected companies'}
                        {!detail.data.is_active && <span className="badge tf-badge-inactive ms-2">Disabled — not billed</span>}
                    </p>
                    <div className="table-responsive">
                        <table className="table table-sm align-middle mb-0">
                            <thead>
                                <tr><th>Company</th><th>Plan</th><th className="text-end">Pays</th><th>Rate</th></tr>
                            </thead>
                            <tbody>
                                {detail.companies.length === 0 && <tr><td colSpan={4} className="tf-empty">Not assigned to any company.</td></tr>}
                                {detail.companies.map((c) => (
                                    <tr key={c.id}>
                                        <td><Link to={`/companies/${c.id}/pricing`}>{c.name}</Link> <code className="small">{c.code}</code></td>
                                        <td className="text-capitalize">{c.pricing_plan}</td>
                                        <td className="text-end fw-semibold">{feeAmount(c.pricing.amount)}</td>
                                        <td>
                                            <RateBadge type={c.pricing.pricing_type} label={c.pricing.rate_label} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            )}
        </Modal>
    );
}
