import { useCallback, useEffect, useState } from 'react';

import PageHeader from '../../../components/PageHeader.jsx';
import { reports } from '../../../lib/api.js';
import { notifyError } from '../../../lib/ui.js';
import ReportToolbar from './ReportToolbar.jsx';

const peso = (n) => `₱${Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const num = (n) => Number(n ?? 0).toLocaleString();
const dt = (v) => (v ? new Date(v).toLocaleString() : '—');

export default function FuelEnergyReport() {
    const [params, setParams] = useState({ from: '', to: '', type: '' });
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(false);

    const run = useCallback(async () => {
        setLoading(true);
        try {
            const clean = Object.fromEntries(Object.entries(params).filter(([, v]) => v !== ''));
            setData(await reports.get('fuel-energy', clean));
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
                title="Fuel & Energy Report"
                subtitle="Fuel purchases and EV charging sessions."
            />

            <ReportToolbar reportKey="fuel-energy" params={params} onRun={run} running={loading}>
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
                <div>
                    <label className="form-label mb-1 small">Type</label>
                    <select className="form-select form-select-sm" value={params.type}
                        onChange={(e) => setParams((p) => ({ ...p, type: e.target.value }))}>
                        <option value="">All</option>
                        <option value="Diesel">Diesel</option>
                        <option value="Gasoline">Gasoline</option>
                        <option value="EV">EV charging</option>
                    </select>
                </div>
            </ReportToolbar>

            <h6 className="fw-bold mt-2">Fuel Purchases</h6>
            <div className="card mb-4">
                <div className="table-responsive">
                    <table className="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Fueled At</th><th>Bus</th><th>Type</th>
                                <th className="text-end">Litres</th><th className="text-end">₱/L</th>
                                <th className="text-end">Amount</th><th>Station</th>
                            </tr>
                        </thead>
                        <tbody>
                            {!loading && (data?.fuel.rows.length ?? 0) === 0 && (
                                <tr><td colSpan={7} className="tf-empty">No fuel purchases.</td></tr>
                            )}
                            {!loading && data?.fuel.rows.map((r) => (
                                <tr key={r.id}>
                                    <td className="small">{dt(r.fueled_at)}</td>
                                    <td className="fw-semibold">{r.bus_number ?? '—'}</td>
                                    <td>{r.fuel_type}</td>
                                    <td className="text-end">{num(r.liters)}</td>
                                    <td className="text-end">{num(r.price_per_liter)}</td>
                                    <td className="text-end">{peso(r.amount_paid)}</td>
                                    <td className="small">{r.station ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                        {!loading && data && (
                            <tfoot>
                                <tr className="fw-bold">
                                    <td colSpan={3}>Total ({data.fuel.totals.fills} fills)</td>
                                    <td className="text-end">{num(data.fuel.totals.total_liters)}</td>
                                    <td className="text-end">{data.fuel.totals.avg_price != null ? num(data.fuel.totals.avg_price) : '—'}</td>
                                    <td className="text-end">{peso(data.fuel.totals.total_cost)}</td>
                                    <td />
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>
            </div>

            <h6 className="fw-bold">EV Charging Sessions</h6>
            <div className="card">
                <div className="table-responsive">
                    <table className="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Started</th><th>Ended</th><th>Bus</th><th>Status</th>
                                <th className="text-end">Battery</th><th className="text-end">Gained</th><th className="text-end">Minutes</th>
                            </tr>
                        </thead>
                        <tbody>
                            {!loading && (data?.charging.rows.length ?? 0) === 0 && (
                                <tr><td colSpan={7} className="tf-empty">No charging sessions.</td></tr>
                            )}
                            {!loading && data?.charging.rows.map((r) => (
                                <tr key={r.id}>
                                    <td className="small">{dt(r.started_at)}</td>
                                    <td className="small">{dt(r.ended_at)}</td>
                                    <td className="fw-semibold">{r.bus_number ?? '—'}</td>
                                    <td>{r.status}</td>
                                    <td className="text-end">{r.battery_start_pct}% → {r.battery_end_pct != null ? `${r.battery_end_pct}%` : '—'}</td>
                                    <td className="text-end">{r.battery_gained_pct != null ? `${r.battery_gained_pct}%` : '—'}</td>
                                    <td className="text-end">{r.duration_minutes}</td>
                                </tr>
                            ))}
                        </tbody>
                        {!loading && data && (
                            <tfoot>
                                <tr className="fw-bold">
                                    <td colSpan={3}>Total</td>
                                    <td>{data.charging.totals.active} active / {data.charging.totals.completed} done</td>
                                    <td colSpan={2} />
                                    <td className="text-end">{data.charging.totals.total_minutes}</td>
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>
            </div>
        </>
    );
}
