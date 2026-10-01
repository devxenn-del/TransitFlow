import { useEffect, useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import DataTable from '../../components/DataTable.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import { companyBilling, companyPricing } from '../../lib/api.js';
import { RateBadge, StatementSummary, billingPeriod, feeAmount, isFree, mdy, peso, php } from '../../lib/billing.jsx';
import { useActiveCompany } from '../../lib/companyScope.jsx';
import { useList } from '../../lib/useList.js';
import { confirmAction, notifyError, notifySuccess, swal } from '../../lib/ui.js';

const STATEMENT_BADGE = {
    unpaid: 'text-bg-warning-subtle text-warning-emphasis',
    paid: 'tf-badge-active',
    void: 'tf-badge-inactive',
};

const thisMonth = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
};

/**
 * Billing & Fees — the company's own assigned fees (as priced for it) and
 * its billing statements. Read-only for company users; inside a company
 * workspace the Super Admin can also generate a statement and record its
 * payment status.
 */
export default function Billing() {
    const { isSuperAdmin, can } = useAuth();
    const company = useActiveCompany();
    const canManage = isSuperAdmin && can('fees.manage');
    const [feeRows, setFeeRows] = useState(null);
    const statements = useList(companyBilling.statements, { perPage: 12 });
    const [viewing, setViewing] = useState(null);
    const [period, setPeriod] = useState(thisMonth());
    const [generating, setGenerating] = useState(false);
    const [showFees, setShowFees] = useState(false);
    // The statement action in progress ('paid' | 'unpaid' | 'void' | 'email'), so
    // its button shows a loader and nothing can be clicked twice meanwhile.
    const [busy, setBusy] = useState(null);

    useEffect(() => {
        companyBilling.fees().then(setFeeRows).catch((err) => { notifyError(err); setFeeRows([]); });
    }, []);

    // Default "period starting in" to the company's current billing period —
    // with a cycle day of e.g. 13, on Oct 1 that period started in September.
    useEffect(() => {
        if (!canManage || !company?.id) return;
        companyPricing.get(company.id).then((p) => setPeriod(p.current_period.start.slice(0, 7))).catch(() => {});
    }, [canManage, company?.id]);

    const open = (s) => companyBilling.statement(s.id).then(setViewing).catch(notifyError);

    const generate = async (e) => {
        e.preventDefault();
        if (generating) return;
        setGenerating(true);
        try {
            const { data: statement, email } = await companyPricing.generateStatement(company.id, period);
            const generated = `Billing No. ${statement.billing_number} generated for ${billingPeriod(statement)}.`;
            if (email?.sent) {
                notifySuccess(`${generated} Emailed to ${email.to}.`);
            } else {
                swal.fire({
                    icon: 'warning',
                    title: 'Statement generated — email not sent',
                    text: `${generated} ${email?.error ?? ''} You can send it later from the statement's details.`,
                });
            }
            statements.reload();
        } catch (err) {
            notifyError(err);
        } finally {
            setGenerating(false);
        }
    };

    /**
     * Asks how much the company actually paid, previewing any excess (deducted
     * from the next bill) or shortage (added to it). Resolves to the amount,
     * or null if cancelled.
     */
    const askAmountReceived = async (s) => {
        const total = Number(s.total);
        const describe = (value) => {
            const diff = Math.round((Number(value) - total) * 100) / 100;
            if (value === '' || Number.isNaN(diff)) return '';
            if (diff === 0) return '<span class="text-success">Paid exactly.</span>';
            return diff > 0
                ? `<span class="text-success">Excess <strong>${php(diff)}</strong> — will be deducted from the next bill.</span>`
                : `<span class="text-warning-emphasis">Short <strong>${php(-diff)}</strong> — will be added to the next bill.</span>`;
        };
        const result = await swal.fire({
            title: `Mark Billing No. ${s.billing_number} paid`,
            html: `Amount to pay: <strong>${php(total)}</strong><div class="small mt-2">Enter the amount actually received:</div>`,
            input: 'number',
            inputValue: total.toFixed(2),
            inputAttributes: { min: '0', step: '0.01' },
            footer: `<div id="tf-pay-diff" class="small">${describe(total)}</div>`,
            showCancelButton: true,
            confirmButtonText: 'Mark paid',
            didOpen: () => {
                const input = swal.getInput();
                input?.addEventListener('input', () => {
                    const el = document.getElementById('tf-pay-diff');
                    if (el) el.innerHTML = describe(input.value);
                });
            },
            inputValidator: (value) => (value === '' || Number(value) < 0 ? 'Enter the amount received (0 or more).' : undefined),
        });
        return result.isConfirmed ? Number(result.value).toFixed(2) : null;
    };

    const setStatus = async (s, status) => {
        if (busy) return;
        let amountReceived = null;
        if (status === 'paid') {
            amountReceived = await askAmountReceived(s);
            if (amountReceived === null) return;
        }
        setBusy(status);
        try {
            const { data: updated, email } = await companyPricing.setStatementStatus(company.id, s.id, status, amountReceived);
            const carry = Number(updated.carry_over_amount);
            const carryNote = status !== 'paid' || carry === 0 ? ''
                : carry > 0 ? ` Short ${php(carry)} will be added to the next bill.` : ` Excess ${php(-carry)} will be deducted from the next bill.`;
            const marked = `Billing No. ${updated.billing_number} marked ${status}.${carryNote}`;
            if (!email) {
                notifySuccess(marked);
            } else if (email.sent) {
                notifySuccess(`${marked} Payment confirmation emailed to ${email.to}.`);
            } else {
                swal.fire({ icon: 'warning', title: 'Marked paid — email not sent', text: `${email.error} You can send it later with "Send email".` });
            }
            setViewing((v) => (v?.id === updated.id ? updated : v));
            statements.reload();
        } catch (err) {
            notifyError(err);
        } finally {
            setBusy(null);
        }
    };

    const sendEmail = async (s) => {
        if (busy) return;
        setBusy('email');
        try {
            const { email } = await companyPricing.emailStatement(company.id, s.id);
            if (email.sent) {
                notifySuccess(`Billing No. ${s.billing_number} ${s.status === 'paid' ? 'payment confirmation' : 'statement'} emailed to ${email.to}.`);
            } else {
                swal.fire({ icon: 'warning', title: 'Email not sent', text: email.error });
            }
        } catch (err) {
            notifyError(err);
        } finally {
            setBusy(null);
        }
    };

    const recalculate = async (s) => {
        if (busy) return;
        if (!(await confirmAction({
            title: `Recalculate Billing No. ${s.billing_number}?`,
            text: "Its lines and total are re-priced with the company's current fees and special rates. The billing number, period and due date stay the same.",
            confirmText: 'Recalculate',
        }))) return;
        setBusy('recalc');
        try {
            const updated = await companyPricing.recalculateStatement(company.id, s.id);
            setViewing(updated);
            statements.reload();
            const changed = updated.total !== s.total;
            notifySuccess(changed
                ? `Billing No. ${updated.billing_number} recalculated: ${php(s.total)} → ${php(updated.total)}. Use "Send email" to send the corrected bill.`
                : `Billing No. ${updated.billing_number} recalculated — the amount is unchanged.`);
        } catch (err) {
            notifyError(err);
        } finally {
            setBusy(null);
        }
    };

    const spinner = (action) => busy === action && <span className="spinner-border spinner-border-sm me-1" />;

    const recurringTotal = (feeRows ?? []).filter((f) => f.billing_frequency === 'monthly').reduce((sum, f) => sum + Number(f.amount), 0);

    // The latest statement still to be paid, else the latest non-void one (listed newest first).
    const currentBill = statements.rows.find((s) => s.status === 'unpaid')
        ?? statements.rows.find((s) => s.status !== 'void')
        ?? null;
    const billIsUnpaid = currentBill?.status === 'unpaid';

    const columns = [
        { key: 'billing_number', header: 'Billing No.', render: (s) => <span className="fw-semibold">{s.billing_number}</span> },
        { key: 'period', header: 'Billing Period', className: 'text-nowrap', render: (s) => billingPeriod(s) },
        { key: 'total', header: 'Amount to Pay', className: 'text-end text-nowrap', render: (s) => <span className="fw-semibold">{php(s.total)}</span> },
        { key: 'due_on', header: 'Due Date', render: (s) => mdy(s.due_on) },
        { key: 'status', header: 'Status', render: (s) => <span className={`badge text-capitalize ${STATEMENT_BADGE[s.status]}`}>{s.status}</span> },
        {
            key: 'actions',
            header: '',
            className: 'text-end',
            render: (s) => <button className="btn btn-sm btn-outline-secondary" onClick={() => open(s)}><i className="bi bi-eye" /></button>,
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Company"
                title="Billing & Fees"
                subtitle="The platform fees assigned to your company and your billing statements."
            />

            {/* The current bill is the focus; the fees behind it sit behind the info icon. */}
            <div className={`card mb-4 tf-current-bill border-2 ${billIsUnpaid ? 'border-warning' : currentBill ? 'border-success-subtle' : ''}`}>
                <div className="card-body">
                    <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <h3 className="h6 text-uppercase text-body-secondary mb-0 d-flex align-items-center gap-2">
                            {currentBill ? 'Current bill' : 'Billing'}
                            {currentBill && <span className={`badge text-capitalize ${STATEMENT_BADGE[currentBill.status]}`}>{currentBill.status}</span>}
                            <button
                                type="button"
                                className="btn btn-link p-0 text-primary lh-1"
                                title="My company fees"
                                aria-label="Show my company fees"
                                onClick={() => setShowFees(true)}
                            >
                                <i className="bi bi-info-circle fs-5" />
                            </button>
                        </h3>
                        {currentBill && (
                            <button className="btn btn-sm btn-outline-secondary" onClick={() => open(currentBill)}>
                                <i className="bi bi-receipt me-1" />View details
                            </button>
                        )}
                    </div>
                    {currentBill
                        ? <StatementSummary statement={currentBill} companyCode={company?.code} />
                        : <p className="text-body-secondary mb-0">No billing statements yet. Tap <i className="bi bi-info-circle" /> to see the fees assigned to your company.</p>}
                </div>
            </div>

            <Modal open={showFees} size="lg" title="My company fees" onClose={() => setShowFees(false)}
                footer={(
                    <>
                        {recurringTotal > 0 && <span className="me-auto small text-body-secondary">Monthly recurring: <strong>{peso(recurringTotal)}</strong></span>}
                        <button className="btn btn-light" onClick={() => setShowFees(false)}>Close</button>
                    </>
                )}
            >
                {feeRows === null && <div className="text-center py-4"><span className="spinner-border spinner-border-sm" /></div>}
                {feeRows?.length === 0 && <p className="text-body-secondary mb-0">No fees are assigned to this company.</p>}
                {feeRows?.length > 0 && (
                    <div className="list-group list-group-flush">
                        {feeRows.map((f) => (
                            <div key={f.fee_id} className="list-group-item px-0">
                                <div className="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                    <div className="min-w-0">
                                        <div className="fw-semibold">
                                            {f.name}
                                            {f.status !== 'active' && <span className="badge text-bg-info-subtle text-info-emphasis text-capitalize ms-2">{f.status}</span>}
                                        </div>
                                        {f.description && <div className="small text-body-secondary">{f.description}</div>}
                                        <div className="small text-body-secondary mt-1">
                                            Effective {mdy(f.effective_date)} · Next billing {mdy(f.next_billing_date)}
                                        </div>
                                    </div>
                                    <div className="text-end">
                                        <div className="fw-semibold">
                                            {isFree(f.amount)
                                                ? <span className="text-success">Free</span>
                                                : <>{peso(f.amount)} <span className="small fw-normal text-body-secondary">/ {f.frequency_label}</span></>}
                                        </div>
                                        <RateBadge type={f.pricing_type} label={f.rate_label} />
                                        {isSuperAdmin && f.pricing_type === 'special' && (
                                            <div className="small text-body-secondary">Standard {feeAmount(f.standard_amount)}</div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </Modal>

            <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <h3 className="h6 text-uppercase text-body-secondary mb-0">Billing statements</h3>
                {canManage && (
                    <form className="d-flex align-items-center gap-2" onSubmit={generate}>
                        <label htmlFor="bill-period" className="small text-body-secondary text-nowrap mb-0">Period starting in</label>
                        <input id="bill-period" type="month" className="form-control form-control-sm" required value={period} onChange={(e) => setPeriod(e.target.value)} />
                        <button className="btn btn-sm btn-primary text-nowrap" disabled={generating}>
                            {generating ? <span className="spinner-border spinner-border-sm me-1" /> : <i className="bi bi-receipt me-1" />}
                            {generating ? 'Generating & emailing…' : 'Generate statement'}
                        </button>
                    </form>
                )}
            </div>
            <DataTable columns={columns} rows={statements.rows} loading={statements.loading} empty="No billing statements yet." />
            <Pagination meta={statements.meta} page={statements.page} onPage={statements.setPage} />

            <Modal
                open={!!viewing}
                size="lg"
                title={viewing ? `Billing No. ${viewing.billing_number}` : ''}
                onClose={() => setViewing(null)}
                footer={(
                    <>
                        {canManage && viewing && (
                            <div className="me-auto d-flex gap-2">
                                {viewing.status !== 'paid' && (
                                    <button className="btn btn-success btn-sm" disabled={!!busy} onClick={() => setStatus(viewing, 'paid')}>
                                        {spinner('paid') || <i className="bi bi-check2-circle me-1" />}{busy === 'paid' ? 'Marking paid & emailing…' : 'Mark paid'}
                                    </button>
                                )}
                                {viewing.status !== 'unpaid' && <button className="btn btn-outline-secondary btn-sm" disabled={!!busy} onClick={() => setStatus(viewing, 'unpaid')}>{spinner('unpaid')}Mark unpaid</button>}
                                {viewing.status === 'unpaid' && (
                                    <button className="btn btn-outline-secondary btn-sm" disabled={!!busy} onClick={() => recalculate(viewing)}>
                                        {spinner('recalc') || <i className="bi bi-arrow-repeat me-1" />}{busy === 'recalc' ? 'Recalculating…' : 'Recalculate'}
                                    </button>
                                )}
                                {viewing.status !== 'void' && <button className="btn btn-outline-danger btn-sm" disabled={!!busy} onClick={() => setStatus(viewing, 'void')}>{spinner('void')}Void</button>}
                                {viewing.status !== 'void' && (
                                    <button className="btn btn-outline-primary btn-sm" disabled={!!busy} onClick={() => sendEmail(viewing)}>
                                        {spinner('email') || <i className="bi bi-envelope me-1" />}{busy === 'email' ? 'Sending…' : 'Send email'}
                                    </button>
                                )}
                            </div>
                        )}
                        <button className="btn btn-light" disabled={!!busy} onClick={() => setViewing(null)}>Close</button>
                    </>
                )}
            >
                {viewing && (
                    <>
                        <div className="border rounded p-3 mb-3">
                            <StatementSummary statement={viewing} companyCode={company?.code} />
                        </div>
                        <div className="d-flex flex-wrap gap-4 small mb-3">
                            <div><div className="text-body-secondary">Issued</div>{mdy(viewing.issued_on)}</div>
                            <div><div className="text-body-secondary">Status</div><span className={`badge text-capitalize ${STATEMENT_BADGE[viewing.status]}`}>{viewing.status}</span></div>
                            {viewing.amount_received !== null && <div><div className="text-body-secondary">Amount received</div>{php(viewing.amount_received)}</div>}
                        </div>
                        {viewing.status === 'paid' && Number(viewing.carry_over_amount) !== 0 && (
                            <div className={`alert py-2 small ${Number(viewing.carry_over_amount) > 0 ? 'alert-warning' : 'alert-success'}`}>
                                {Number(viewing.carry_over_amount) > 0
                                    ? <>Short <strong>{php(viewing.carry_over_amount)}</strong> — </>
                                    : <>Excess <strong>{php(-viewing.carry_over_amount)}</strong> — </>}
                                {viewing.carried_to_billing_number
                                    ? <>carried to Billing No. {viewing.carried_to_billing_number}.</>
                                    : <>{Number(viewing.carry_over_amount) > 0 ? 'will be added to' : 'will be deducted from'} the next bill.</>}
                            </div>
                        )}
                        <table className="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Fee</th><th>Billing</th><th>Rate</th>
                                    {isSuperAdmin && <th className="text-end">Standard</th>}
                                    <th className="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                {viewing.items.map((i) => (i.kind === 'carry_over' ? (
                                    <tr key={i.id} className="table-light">
                                        <td colSpan={isSuperAdmin ? 4 : 3}>
                                            <i className={`bi ${Number(i.amount) < 0 ? 'bi-arrow-down-circle text-success' : 'bi-arrow-up-circle text-warning'} me-1`} />
                                            {i.fee_name}
                                            {i.fee_description && <div className="small text-body-secondary">{i.fee_description}</div>}
                                        </td>
                                        <td className="text-end">{Number(i.amount) < 0 ? `−${php(-i.amount)}` : `+${php(i.amount)}`}</td>
                                    </tr>
                                ) : (
                                    <tr key={i.id}>
                                        <td>{i.fee_name}</td>
                                        <td>{i.frequency_label}</td>
                                        <td><RateBadge type={i.pricing_type} label={i.rate_label} /></td>
                                        {isSuperAdmin && <td className="text-end text-body-secondary">{feeAmount(i.standard_amount)}</td>}
                                        <td className="text-end">{feeAmount(i.amount)}</td>
                                    </tr>
                                )))}
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colSpan={isSuperAdmin ? 4 : 3} className="text-end">Total</th>
                                    <th className="text-end">{php(viewing.total)}</th>
                                </tr>
                            </tfoot>
                        </table>
                        <div className="form-text">Amounts are as charged when the statement was generated.</div>
                    </>
                )}
            </Modal>
        </>
    );
}
