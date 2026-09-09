import { useCallback, useEffect, useState } from 'react';

import PageHeader from '../../../components/PageHeader.jsx';
import { reports } from '../../../lib/api.js';
import { notifyError } from '../../../lib/ui.js';
import ReportToolbar from './ReportToolbar.jsx';

const DENOMS = [1000, 500, 200, 100, 50, 20, 10, 5, 1];
const peso = (n) => `₱${Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const today = () => new Date().toISOString().slice(0, 10);

export default function CashCountReport() {
    const [params, setParams] = useState({ from: today(), to: today() });
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(false);

    const run = useCallback(async () => {
        setLoading(true);
        try {
            setData(await reports.get('cash-count', params));
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
                title="Cash Count Report"
                subtitle="Per bus / operational-day / shift denomination rollups, built from received remittances."
            />

            <ReportToolbar reportKey="cash-count" params={params} onRun={run} running={loading}>
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

            <div className="card">
                <div className="table-responsive">
                    <table className="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Op. Date</th><th>Bus</th><th>Shift</th>
                                {DENOMS.map((d) => <th key={d} className="text-end">{d}</th>)}
                                <th className="text-end">Counted</th>
                                <th className="text-end">Remitted</th>
                                <th className="text-end">Variance</th>
                                <th className="text-end">Net Cash</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading && (
                                <tr><td colSpan={7 + DENOMS.length} className="tf-empty"><span className="spinner-border spinner-border-sm" /></td></tr>
                            )}
                            {!loading && (data?.rows.length ?? 0) === 0 && (
                                <tr><td colSpan={7 + DENOMS.length} className="tf-empty">No cash counts in this range.</td></tr>
                            )}
                            {!loading && data?.rows.map((r) => (
                                <tr key={r.id}>
                                    <td>{r.op_date}</td>
                                    <td className="fw-semibold">{r.bus_number ?? '—'}</td>
                                    <td>{r.shift}{r.adjusted && <i className="bi bi-pencil-square text-warning ms-1" title="Manually adjusted" />}</td>
                                    {DENOMS.map((d) => <td key={d} className="text-end">{r.denominations[d] ?? 0}</td>)}
                                    <td className="text-end">{peso(r.counted_total)}</td>
                                    <td className="text-end">{peso(r.remitted_total)}</td>
                                    <td className="text-end">{peso(r.variance)}</td>
                                    <td className="text-end">{peso(r.net_cash)}</td>
                                </tr>
                            ))}
                        </tbody>
                        {!loading && (data?.rows.length ?? 0) > 0 && (
                            <tfoot>
                                <tr className="fw-bold">
                                    <td colSpan={3}>Total</td>
                                    {DENOMS.map((d) => <td key={d} className="text-end">{data.denom_totals[d] ?? 0}</td>)}
                                    <td className="text-end">{peso(data.grand_total)}</td>
                                    <td className="text-end">{peso(data.remitted_total)}</td>
                                    <td className="text-end">—</td>
                                    <td className="text-end">{peso(data.net_cash_total)}</td>
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>
            </div>
        </>
    );
}
