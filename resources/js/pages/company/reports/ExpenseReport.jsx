import { useCallback, useEffect, useState } from 'react';

import PageHeader from '../../../components/PageHeader.jsx';
import { reports } from '../../../lib/api.js';
import { notifyError } from '../../../lib/ui.js';
import ReportToolbar from './ReportToolbar.jsx';

const peso = (n) => `₱${Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const today = () => new Date().toISOString().slice(0, 10);

export default function ExpenseReport() {
    const [params, setParams] = useState({ from: today(), to: today() });
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(false);

    const run = useCallback(async () => {
        setLoading(true);
        try {
            setData(await reports.get('expenses', params));
        } catch (e) {
            notifyError(e);
        } finally {
            setLoading(false);
        }
    }, [params]);

    useEffect(() => { run(); }, [run]);

    return (
        <>
            <PageHeader
                eyebrow="Reports & Analytics"
                title="Operational Expense Report"
                subtitle="Active operational expenses grouped by operational day."
            />

            <ReportToolbar reportKey="expenses" params={params} onRun={run} running={loading}>
                <div>
                    <label className="form-label mb-1 small">From</label>
                    <input type="date" className="form-control form-control-sm" value={params.from}
                        onChange={(e) => setParams((p) => ({ ...p, from: e.target.value }))} />
                </div>
                <div>
                    <label className="form-label mb-1 small">To</label>
                    <input type="date" className="form-control form-control-sm" value={params.to}
                        onChange={(e) => setParams((p) => ({ ...p, to: e.target.value }))} />
                </div>
            </ReportToolbar>

            {loading && <div className="tf-empty"><span className="spinner-border spinner-border-sm" /></div>}

            {!loading && data && data.days.length === 0 && (
                <div className="card"><div className="tf-empty">No expenses in this range.</div></div>
            )}

            {!loading && data?.days.map((day) => (
                <div className="card mb-3" key={day.op_date}>
                    <div className="card-header d-flex justify-content-between align-items-center bg-white">
                        <span className="fw-semibold">{day.op_date}</span>
                        <span className="fw-semibold">{peso(day.total)}</span>
                    </div>
                    <div className="table-responsive">
                        <table className="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Bus</th><th>Shift</th><th>Category</th><th>Description</th>
                                    <th className="text-end">Amount</th><th>Recorded By</th>
                                </tr>
                            </thead>
                            <tbody>
                                {day.items.map((item) => (
                                    <tr key={item.id}>
                                        <td>{item.bus_number ?? '—'}</td>
                                        <td>{item.shift}</td>
                                        <td>{item.category}</td>
                                        <td>{item.description}</td>
                                        <td className="text-end">{peso(item.amount)}</td>
                                        <td className="small text-muted">{item.recorded_by_name ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            ))}

            {!loading && data && data.days.length > 0 && (
                <div className="text-end fw-bold">Grand total — {peso(data.grand_total)}</div>
            )}
        </>
    );
}
