import { useCallback, useEffect, useState } from 'react';

import DataTable from '../../../components/DataTable.jsx';
import PageHeader from '../../../components/PageHeader.jsx';
import { reports } from '../../../lib/api.js';
import { notifyError } from '../../../lib/ui.js';
import ReportToolbar from './ReportToolbar.jsx';

const peso = (n) => `₱${Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const today = () => new Date().toISOString().slice(0, 10);

const COLUMNS = [
    { key: 'bus_number', header: 'Bus', render: (r) => <span className="fw-semibold">{r.bus_number}</span> },
    { key: 'trips_count', header: 'Trips', className: 'text-end' },
    { key: 'passenger_total', header: 'Pax', className: 'text-end' },
    { key: 'gross_income', header: 'Gross', className: 'text-end', render: (r) => peso(r.gross_income) },
    { key: 'dispatch_total', header: 'Dispatch', className: 'text-end', render: (r) => peso(r.dispatch_total) },
    { key: 'operational_expenses', header: 'Op. Exp.', className: 'text-end', render: (r) => peso(r.operational_expenses) },
    { key: 'remaining_income', header: 'Remaining', className: 'text-end', render: (r) => <span className="fw-semibold">{peso(r.remaining_income)}</span> },
    { key: 'cash_counted', header: 'Cash Counted', className: 'text-end', render: (r) => peso(r.cash_counted) },
    { key: 'morning_net', header: 'AM Net', className: 'text-end', render: (r) => peso(r.morning_net) },
    { key: 'evening_net', header: 'PM Net', className: 'text-end', render: (r) => peso(r.evening_net) },
];

export default function DailyOperations() {
    const [params, setParams] = useState({ from: today(), to: today() });
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(false);

    const run = useCallback(async () => {
        setLoading(true);
        try {
            setData(await reports.get('daily-operations', params));
        } catch (e) {
            notifyError(e);
        } finally {
            setLoading(false);
        }
    }, [params]);

    useEffect(() => { run(); }, [run]);

    const s = data?.summary;

    return (
        <>
            <PageHeader
                eyebrow="Reports & Analytics"
                title="Daily Operations Report"
                subtitle="One consolidated row per bus per operational day — gross, dispatch, expenses, remaining income and shift nets."
            />

            <ReportToolbar reportKey="daily-operations" params={params} onRun={run} running={loading}>
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

            {s && (
                <div className="text-muted small mb-2">
                    {data.range.from} → {data.range.to} · gross {peso(s.gross_income)} · remaining{' '}
                    <span className="fw-semibold">{peso(s.remaining_income)}</span> · cash on hand {peso(s.cash_on_hand)}
                </div>
            )}

            <DataTable
                columns={COLUMNS}
                rows={data?.rows ?? []}
                loading={loading}
                empty="No buses."
                rowKey={(r) => r.bus_id}
            />
        </>
    );
}
