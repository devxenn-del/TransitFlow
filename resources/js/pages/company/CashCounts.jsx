import { useCallback, useEffect, useMemo, useState } from 'react';
import { swal as Swal } from '../../lib/ui.js';

import { useAuth } from '../../auth/AuthContext.jsx';
import DataTable from '../../components/DataTable.jsx';
import DenominationTable from '../../components/DenominationTable.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import { cashCounts } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';
import { notifyError, notifySuccess } from '../../lib/ui.js';

const DENOMS = [1000, 500, 200, 100, 50, 20, 10, 5, 1];
const peso = (n) => `₱${Number(n ?? 0).toLocaleString()}`;
const dt = (v) => (v ? new Date(v).toLocaleString() : '—');
const denFrom = (obj) => Object.fromEntries(DENOMS.map((d) => [`q${d}`, String(obj?.[`q${d}`] ?? 0)]));

function VarianceBadge({ v }) {
    if (!v) return <span className="badge text-bg-success">balanced</span>;
    return v < 0
        ? <span className="badge text-bg-danger">short {peso(-v)}</span>
        : <span className="badge text-bg-warning">over {peso(v)}</span>;
}

export default function CashCounts() {
    const { can } = useAuth();
    const canAdjust = can('cashcount.adjust');
    const [filters, setFilters] = useState({ date: '', shift: '', variance_only: false });
    const [detail, setDetail] = useState(null);
    const [den, setDen] = useState({});
    const [saving, setSaving] = useState(false);

    const fetcher = useCallback(
        (params) => cashCounts.list({
            ...params,
            ...(filters.date ? { date: filters.date } : {}),
            ...(filters.shift ? { shift: filters.shift } : {}),
            ...(filters.variance_only ? { variance_only: 1 } : {}),
        }),
        [filters],
    );
    const { rows, meta, loading, page, setPage, reload } = useList(fetcher, { deps: [filters] });

    const open = async (row) => {
        try { setDetail(await cashCounts.get(row.id)); } catch (e) { notifyError(e); }
    };
    useEffect(() => { if (detail) setDen(denFrom(detail.data.denominations)); }, [detail]);

    const denTotal = useMemo(() => DENOMS.reduce((s, d) => s + d * (Number(den[`q${d}`]) || 0), 0), [den]);
    const dirty = detail && Number(detail.data.counted_total) !== denTotal;

    const saveAdjustment = async () => {
        const { isConfirmed, value } = await Swal.fire({
            title: 'Adjust denominations',
            html: `<div class="text-start small text-muted mb-2">Counted total ${peso(detail.data.counted_total)} → <b>${peso(denTotal)}</b></div>`
                + '<input id="reason" class="swal2-input" placeholder="Reason (required)">'
                + '<input id="vpin" type="password" inputmode="numeric" class="swal2-input" placeholder="Your void PIN">',
            footer: 'Set your void PIN from the account menu (top-right) if you haven\'t yet.',
            focusConfirm: false, showCancelButton: true, confirmButtonText: 'Save adjustment',
            preConfirm: () => {
                const reason = document.getElementById('reason').value;
                if (!reason) return Swal.showValidationMessage('A reason is required');
                return { reason, pin: document.getElementById('vpin').value };
            },
        });
        if (!isConfirmed) return;
        setSaving(true);
        try {
            const payload = { ...value };
            DENOMS.forEach((d) => { payload[`q${d}`] = Number(den[`q${d}`]) || 0; });
            const updated = await cashCounts.adjust(detail.data.id, payload);
            notifySuccess('Denominations adjusted.');
            setDetail((prev) => ({ ...prev, data: updated }));
            reload();
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const columns = useMemo(() => [
        { key: 'bus', header: 'Bus', render: (r) => <span className="fw-semibold">{r.bus?.bus_number ?? `#${r.bus_id}`}</span> },
        { key: 'day', header: 'Date · shift', render: (r) => <span className="small">{r.op_date} · {r.shift}</span> },
        { key: 'trips', header: 'Trips', className: 'text-end', render: (r) => r.trip_count },
        { key: 'remit', header: 'Remitted', className: 'text-end', render: (r) => peso(r.remitted_total) },
        {
            key: 'counted', header: 'Counted', className: 'text-end',
            render: (r) => <>{peso(r.counted_total)}{r.adjusted_at && <i className="bi bi-pencil-square text-warning ms-1" title="Manually adjusted" />}</>,
        },
        { key: 'var', header: 'Variance', render: (r) => <VarianceBadge v={r.variance} /> },
        {
            key: 'actions', header: '', className: 'text-end',
            render: (r) => (
                <button className="btn btn-sm btn-outline-secondary" onClick={() => open(r)}>
                    <i className="bi bi-eye me-1" /> Breakdown
                </button>
            ),
        },
    ], []);

    return (
        <>
            <PageHeader
                eyebrow="Operations"
                title="Cash Count"
                subtitle="Per bus, day and shift — built automatically from received remittances. A manager can re-tally the denominations."
            />

            <div className="d-flex flex-wrap gap-2 mb-3 align-items-end">
                <div>
                    <label className="form-label mb-1 small">Date</label>
                    <input type="date" className="form-control form-control-sm" value={filters.date}
                        onChange={(e) => setFilters((f) => ({ ...f, date: e.target.value }))} />
                </div>
                <div>
                    <label className="form-label mb-1 small">Shift</label>
                    <select className="form-select form-select-sm" value={filters.shift}
                        onChange={(e) => setFilters((f) => ({ ...f, shift: e.target.value }))}>
                        <option value="">All</option>
                        <option>Morning</option>
                        <option>Evening</option>
                    </select>
                </div>
                <div className="form-check ms-2">
                    <input id="var-only" type="checkbox" className="form-check-input" checked={filters.variance_only}
                        onChange={(e) => setFilters((f) => ({ ...f, variance_only: e.target.checked }))} />
                    <label htmlFor="var-only" className="form-check-label small">Variance only</label>
                </div>
                {(filters.date || filters.shift || filters.variance_only) && (
                    <button className="btn btn-sm btn-link" onClick={() => setFilters({ date: '', shift: '', variance_only: false })}>Clear</button>
                )}
            </div>

            <DataTable columns={columns} rows={rows} loading={loading} empty="No cash rollups yet — they appear once remittances are received." />
            <Pagination meta={meta} page={page} onPage={setPage} />

            <Modal
                open={!!detail}
                title={detail ? `${detail.data.bus?.bus_number ?? ''} · ${detail.data.op_date} · ${detail.data.shift}` : ''}
                onClose={() => setDetail(null)}
                size="lg"
                footer={(
                    <>
                        {canAdjust && (
                            <button className="btn btn-primary me-auto" disabled={saving || !dirty} onClick={saveAdjustment}>
                                {saving && <span className="spinner-border spinner-border-sm me-2" />}
                                Save adjustment
                            </button>
                        )}
                        <button className="btn btn-light" onClick={() => setDetail(null)}>Close</button>
                    </>
                )}
            >
                {detail && (
                    <div className="vstack gap-3">
                        <div className="d-flex flex-wrap gap-4">
                            <div><div className="tf-stat-label">Remitted</div><div className="fw-semibold">{peso(detail.data.remitted_total)}</div></div>
                            <div><div className="tf-stat-label">Counted</div><div className="fw-semibold">{peso(detail.data.counted_total)}</div></div>
                            <div><div className="tf-stat-label">Expenses</div><div className="fw-semibold">−{peso(detail.data.expenses_total)}</div></div>
                            <div><div className="tf-stat-label">Net cash</div><div className="fw-semibold">{peso(detail.data.net_cash)}</div></div>
                            <div><div className="tf-stat-label">Variance</div><VarianceBadge v={detail.data.variance} /></div>
                        </div>

                        {detail.data.adjusted_at && (
                            <div className="alert alert-warning small mb-0">
                                <i className="bi bi-pencil-square me-1" />
                                Adjusted {dt(detail.data.adjusted_at)} by {detail.data.adjusted_by} — {detail.data.adjustment_reason}
                            </div>
                        )}

                        <div>
                            <div className="fw-semibold mb-1">Denominations {canAdjust && <span className="small text-muted">— editable, void-PIN required to save</span>}</div>
                            <DenominationTable value={den} onChange={setDen} disabled={!canAdjust} />
                            {dirty && (
                                <div className="small text-primary mt-1">
                                    Unsaved change: counted total would become {peso(denTotal)}.
                                </div>
                            )}
                        </div>

                        <div className="card">
                            <div className="card-header bg-white fw-semibold">Received remittances ({detail.contributions.length})</div>
                            <div className="table-responsive">
                                <table className="table table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th>Trip</th>
                                            <th className="text-end">Counted</th>
                                            <th className="text-end">Expected</th>
                                            <th>Status</th>
                                            <th>Received by</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {detail.contributions.map((c) => (
                                            <tr key={c.id}>
                                                <td>#{c.trip_id}</td>
                                                <td className="text-end fare-amount">{peso(c.counted_total)}</td>
                                                <td className="text-end">{peso(c.expected_amount)}</td>
                                                <td>{c.status === 'Voided'
                                                    ? <span className="badge text-bg-secondary" title={c.void_reason}>voided</span>
                                                    : <span className="badge text-bg-success">received</span>}</td>
                                                <td className="small">{c.received_by ?? '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        {detail.history.length > 0 && (
                            <div className="card">
                                <div className="card-header bg-white fw-semibold">Ledger</div>
                                <table className="table table-sm mb-0">
                                    <tbody>
                                        {detail.history.map((h) => (
                                            <tr key={h.id}>
                                                <td className="small">{h.type}</td>
                                                <td className="small">{h.description}</td>
                                                <td className="text-end fare-amount">{peso(h.amount)}</td>
                                                <td className="small text-muted">{dt(h.created_at)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}
            </Modal>
        </>
    );
}
