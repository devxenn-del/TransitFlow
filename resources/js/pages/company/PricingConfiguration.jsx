import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';

import { useAuth } from '../../auth/AuthContext.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import { companyPricing } from '../../lib/api.js';
import { RateBadge, feeAmount, fmtDate, mdy, todayIso } from '../../lib/billing.jsx';
import { useActiveCompany } from '../../lib/companyScope.jsx';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

const RATE_STATE_BADGE = {
    active: 'tf-badge-active',
    scheduled: 'text-bg-info-subtle text-info-emphasis',
    expired: 'tf-badge-inactive',
    inactive: 'tf-badge-inactive',
};

const BLANK_RATE = { fee_id: '', amount: '', starts_on: '', ends_on: '', is_active: true, internal_notes: '' };

/**
 * Pricing Configuration (company workspace, Super Admin only): Standard vs
 * Special pricing for this company, what it pays per fee today, and its
 * special rates with their internal notes. Special rates only apply while
 * the company is on Special pricing; the backend resolves the price.
 */
export default function PricingConfiguration() {
    const { can } = useAuth();
    const canManage = can('fees.manage');
    const company = useActiveCompany();
    const [data, setData] = useState(null);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(BLANK_RATE);
    const [saving, setSaving] = useState(false);

    const load = useCallback(() => companyPricing.get(company.id).then(setData).catch(notifyError), [company.id]);
    useEffect(() => { load(); }, [load]);

    const [settings, setSettings] = useState({ billing_cycle_day: '', next_billing_number: '' });
    const [savingSettings, setSavingSettings] = useState(false);
    useEffect(() => {
        if (data) setSettings({ billing_cycle_day: data.billing_cycle_day, next_billing_number: data.next_billing_number });
    }, [data?.billing_cycle_day, data?.next_billing_number]); // eslint-disable-line react-hooks/exhaustive-deps

    const saveSettings = async (e) => {
        e.preventDefault();
        setSavingSettings(true);
        try {
            setData(await companyPricing.updateSettings(company.id, {
                billing_cycle_day: Number(settings.billing_cycle_day),
                next_billing_number: Number(settings.next_billing_number),
            }));
            notifySuccess('Billing settings saved.');
        } catch (err) {
            notifyError(err);
        } finally {
            setSavingSettings(false);
        }
    };

    const setPlan = async (plan) => {
        if (plan === data.pricing_plan) return;
        if (!(await confirmAction({
            title: plan === 'special' ? 'Switch to Special Pricing?' : 'Switch to Standard Pricing?',
            text: plan === 'special'
                ? `${company.name}'s active special rates will replace the standard amounts. Other companies are not affected.`
                : `${company.name} will be billed the standard amount for every fee. Special rates are kept but ignored.`,
            confirmText: 'Switch',
        }))) return;
        try {
            setData(await companyPricing.setPlan(company.id, plan));
            notifySuccess('Pricing configuration updated.');
        } catch (err) {
            notifyError(err);
        }
    };

    const openNew = (feeId = '') => { setForm({ ...BLANK_RATE, fee_id: feeId, starts_on: todayIso() }); setEditing({}); };
    const openEdit = (r) => {
        setForm({
            fee_id: r.fee_id,
            amount: r.amount,
            starts_on: r.starts_on,
            ends_on: r.ends_on ?? '',
            is_active: r.is_active,
            internal_notes: r.internal_notes ?? '',
        });
        setEditing(r);
    };

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const payload = { ...form, fee_id: Number(form.fee_id), ends_on: form.ends_on || null, internal_notes: form.internal_notes || null };
            if (editing.id) {
                await companyPricing.updateRate(company.id, editing.id, payload);
                notifySuccess('Special rate updated.');
            } else {
                await companyPricing.createRate(company.id, payload);
                notifySuccess('Special rate added.');
            }
            setEditing(null);
            load();
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const remove = async (r) => {
        if (!(await confirmAction({
            title: `Remove the ${r.fee?.name} special rate?`,
            text: 'This company goes back to the standard rate for this fee (unless another special rate is in effect). Past statements are unchanged.',
            danger: true,
            confirmText: 'Remove',
        }))) return;
        try {
            await companyPricing.removeRate(company.id, r.id);
            notifySuccess('Special rate removed.');
            load();
        } catch (err) {
            notifyError(err);
        }
    };

    if (!data) {
        return <div className="text-center py-5"><span className="spinner-border text-primary" /></div>;
    }

    const isSpecial = data.pricing_plan === 'special';
    const selectedFee = data.available_fees.find((f) => f.id === Number(form.fee_id));

    return (
        <>
            <PageHeader
                eyebrow="Billing"
                title="Pricing Configuration"
                subtitle="Super Admin only. The company sees its resulting fees under Billing & Fees — never this configuration or its notes."
                actions={<Link to="/super-admin/fees" className="btn btn-outline-secondary"><i className="bi bi-cash-stack me-1" />Fee Management</Link>}
            />

            <div className="card mb-4">
                <div className="card-body">
                    <h3 className="h6 mb-3">Pricing configuration</h3>
                    <div className="d-flex flex-wrap gap-3">
                        {[
                            ['standard', 'Standard Pricing', 'Billed the standard rate of every assigned fee.'],
                            ['special', 'Special Pricing', 'Active special rates below replace the standard rate for this company only.'],
                        ].map(([value, label, help]) => (
                            <label key={value} className={`border rounded p-3 flex-fill ${data.pricing_plan === value ? 'border-primary bg-primary-subtle' : ''}`} style={{ minWidth: 240, cursor: canManage ? 'pointer' : 'default' }}>
                                <div className="form-check mb-1">
                                    <input type="radio" className="form-check-input" name="pricing_plan" disabled={!canManage} checked={data.pricing_plan === value} onChange={() => setPlan(value)} />
                                    <span className="form-check-label fw-semibold">{label}</span>
                                </div>
                                <div className="small text-body-secondary">{help}</div>
                            </label>
                        ))}
                    </div>
                </div>
            </div>

            <div className="card mb-4">
                <div className="card-body">
                    <h3 className="h6 mb-1">Billing cycle</h3>
                    <p className="small text-body-secondary mb-3">
                        Current period: <strong>{mdy(data.current_period.start)}-{mdy(data.current_period.end)}</strong>.
                        A statement is generated the day after each period ends and is due 21 days later.
                    </p>
                    <form className="row g-2 align-items-end" onSubmit={saveSettings}>
                        <div className="col-sm-4 col-lg-3">
                            <label htmlFor="cycle-day" className="form-label small">Period starts on day</label>
                            <select id="cycle-day" className="form-select" disabled={!canManage} value={settings.billing_cycle_day} onChange={(e) => setSettings({ ...settings, billing_cycle_day: e.target.value })}>
                                {Array.from({ length: 28 }, (_, i) => i + 1).map((d) => <option key={d} value={d}>{d}</option>)}
                            </select>
                        </div>
                        <div className="col-sm-4 col-lg-3">
                            <label htmlFor="next-number" className="form-label small">Next billing number</label>
                            <input id="next-number" type="number" min="1" className="form-control" disabled={!canManage} required value={settings.next_billing_number} onChange={(e) => setSettings({ ...settings, next_billing_number: e.target.value })} />
                        </div>
                        {canManage && (
                            <div className="col-sm-4 col-lg-3">
                                <button className="btn btn-primary" disabled={savingSettings}>
                                    {savingSettings && <span className="spinner-border spinner-border-sm me-2" />}Save
                                </button>
                            </div>
                        )}
                    </form>
                </div>
            </div>

            <h3 className="h6 text-uppercase text-body-secondary mb-2">Fees billed to this company today</h3>
            <div className="card mb-4">
                <div className="table-responsive">
                    <table className="table align-middle mb-0">
                        <thead>
                            <tr><th>Fee</th><th>Billing</th><th className="text-end">Standard</th><th className="text-end">Charged</th><th>Rate</th><th>Next billing</th><th /></tr>
                        </thead>
                        <tbody>
                            {data.fees.length === 0 && <tr><td colSpan={7} className="tf-empty">No active fees are assigned to this company.</td></tr>}
                            {data.fees.map((f) => (
                                <tr key={f.fee_id}>
                                    <td className="fw-semibold">{f.name}</td>
                                    <td>{f.frequency_label}</td>
                                    <td className="text-end text-body-secondary">{feeAmount(f.standard_amount)}</td>
                                    <td className="text-end fw-semibold">{feeAmount(f.amount)}</td>
                                    <td>
                                        <RateBadge type={f.pricing_type} label={f.rate_label} />
                                        {f.special_rate_ends_on && <div className="small text-body-secondary">until {fmtDate(f.special_rate_ends_on)}</div>}
                                    </td>
                                    <td>{fmtDate(f.next_billing_date)}</td>
                                    <td className="text-end">
                                        {canManage && f.pricing_type === 'standard' && (
                                            <button className="btn btn-sm btn-outline-secondary text-nowrap" onClick={() => openNew(f.fee_id)}>Set special rate</button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <div className="d-flex align-items-center justify-content-between mb-2">
                <h3 className="h6 text-uppercase text-body-secondary mb-0">Special rates</h3>
                {canManage && <button className="btn btn-sm btn-accent" onClick={() => openNew()}><i className="bi bi-plus-lg me-1" />Add special rate</button>}
            </div>
            {!isSpecial && data.special_rates.length > 0 && (
                <div className="alert alert-warning small py-2">This company is on Standard Pricing, so these special rates are not applied. Switch to Special Pricing to use them.</div>
            )}
            <div className="card">
                <div className="table-responsive">
                    <table className="table align-middle mb-0">
                        <thead>
                            <tr><th>Fee</th><th className="text-end">Special rate</th><th>Effective</th><th>Expires</th><th>State</th><th>Internal notes</th><th /></tr>
                        </thead>
                        <tbody>
                            {data.special_rates.length === 0 && <tr><td colSpan={7} className="tf-empty">No special rates for this company.</td></tr>}
                            {data.special_rates.map((r) => (
                                <tr key={r.id}>
                                    <td>
                                        <div className="fw-semibold">{r.fee?.name}</div>
                                        <div className="small text-body-secondary">Standard {feeAmount(r.fee?.amount)} · {r.fee?.frequency_label}</div>
                                    </td>
                                    <td className="text-end fw-semibold">{feeAmount(r.amount)}</td>
                                    <td>{fmtDate(r.starts_on)}</td>
                                    <td>{r.ends_on ? fmtDate(r.ends_on) : <span className="text-muted">No expiry</span>}</td>
                                    <td><span className={`badge text-capitalize ${RATE_STATE_BADGE[r.state]}`}>{r.state}</span></td>
                                    <td className="small text-body-secondary" style={{ maxWidth: 260 }}>{r.internal_notes || '—'}</td>
                                    <td className="text-end text-nowrap">
                                        {canManage && (
                                            <>
                                                <button className="btn btn-sm btn-outline-secondary me-1" onClick={() => openEdit(r)}><i className="bi bi-pencil" /></button>
                                                <button className="btn btn-sm btn-outline-danger" onClick={() => remove(r)}><i className="bi bi-trash" /></button>
                                            </>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <Modal
                open={!!editing}
                title={editing?.id ? 'Edit special rate' : `Add special rate — ${company.name}`}
                onClose={() => setEditing(null)}
                footer={
                    <>
                        <button className="btn btn-light" onClick={() => setEditing(null)}>Cancel</button>
                        <button className="btn btn-primary" form="rate-form" disabled={saving}>
                            {saving && <span className="spinner-border spinner-border-sm me-2" />}Save
                        </button>
                    </>
                }
            >
                <form id="rate-form" onSubmit={save} className="vstack gap-3">
                    <div>
                        <label className="form-label">Fee *</label>
                        <select className="form-select" required value={form.fee_id} onChange={(e) => setForm({ ...form, fee_id: e.target.value })}>
                            <option value="">— Select a fee —</option>
                            {data.available_fees.map((f) => (
                                <option key={f.id} value={f.id}>{f.name} — standard {feeAmount(f.amount)} / {f.frequency_label}{f.is_active ? '' : ' (disabled)'}</option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="form-label">Special amount (₱) *</label>
                        <input type="number" min="0" step="0.01" className="form-control" required value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
                        {selectedFee && <div className="form-text">Standard rate: {feeAmount(selectedFee.amount)}. The standard rate and other companies are not changed.</div>}
                    </div>
                    <div className="row g-2">
                        <div className="col-md-6">
                            <label className="form-label">Effective from *</label>
                            <input type="date" className="form-control" required value={form.starts_on} onChange={(e) => setForm({ ...form, starts_on: e.target.value })} />
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Expires on</label>
                            <input type="date" className="form-control" value={form.ends_on} min={form.starts_on || undefined} onChange={(e) => setForm({ ...form, ends_on: e.target.value })} />
                            <div className="form-text">Optional. Afterwards the standard rate applies.</div>
                        </div>
                    </div>
                    <div className="form-check form-switch">
                        <input id="rate-active" type="checkbox" className="form-check-input" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
                        <label htmlFor="rate-active" className="form-check-label">Active</label>
                    </div>
                    <div>
                        <label className="form-label">Internal notes</label>
                        <textarea className="form-control" rows={3} maxLength={2000} value={form.internal_notes} onChange={(e) => setForm({ ...form, internal_notes: e.target.value })} placeholder="Why this company has special pricing — never shown to the company." />
                    </div>
                    {!isSpecial && <div className="alert alert-info small py-2 mb-0">This company is on Standard Pricing. The rate is saved but only applied after switching to Special Pricing.</div>}
                </form>
            </Modal>
        </>
    );
}
