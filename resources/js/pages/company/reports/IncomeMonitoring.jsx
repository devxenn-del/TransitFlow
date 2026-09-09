import { useCallback, useEffect, useState } from 'react';

import DataTable from '../../../components/DataTable.jsx';
import PageHeader from '../../../components/PageHeader.jsx';
import { reports } from '../../../lib/api.js';
import { notifyError } from '../../../lib/ui.js';
import ReportToolbar from './ReportToolbar.jsx';

const peso = (n) => `₱${Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const COLUMNS = [
    { key: 'bus_number', header: 'Bus', render: (r) => <span className="fw-semibold">{r.bus_number}</span> },
    { key: 'plate_number', header: 'Plate' },
    { key: 'trip_count', header: 'Trips', className: 'text-end' },
    { key: 'ticket_count', header: 'Tickets', className: 'text-end' },
    { key: 'income', header: 'Income', className: 'text-end', render: (r) => peso(r.income) },
];

export default function IncomeMonitoring() {
    const [params, setParams] = useState({ period: 'daily', date: new Date().toISOString().slice(0, 10) });
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(false);

    const run = useCallback(async () => {
        setLoading(true);
        try {
            setData(await reports.get('income', params));
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
                title="Income Monitoring"
                subtitle="Per-bus fare income for a day, week or month."
            />

            <ReportToolbar reportKey="income" params={params} onRun={run} running={loading}>
                <div>
                    <label className="form-label mb-1 small">Period</label>
                    <select
                        className="form-select form-select-sm"
                        value={params.period}
                        onChange={(e) => setParams((p) => ({ ...p, period: e.target.value }))}
                    >
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </div>
                <div>
                    <label className="form-label mb-1 small">Reference date</label>
                    <input
                        type="date"
                        className="form-control form-control-sm"
                        value={params.date}
                        onChange={(e) => setParams((p) => ({ ...p, date: e.target.value }))}
                    />
                </div>
            </ReportToolbar>

            {data && (
                <div className="text-muted small mb-2">
                    {data.range_label} · {data.total_trips} trips · {data.total_tickets} tickets ·{' '}
                    <span className="fw-semibold">{peso(data.total_income)}</span>
                </div>
            )}

            <DataTable
                columns={COLUMNS}
                rows={data?.rows ?? []}
                loading={loading}
                empty="No activity in this period."
                rowKey={(r) => r.bus_id}
            />
        </>
    );
}
