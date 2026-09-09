import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';

import { useAuth } from '../auth/AuthContext.jsx';
import PageHeader from '../components/PageHeader.jsx';
import { companies, dashboard } from '../lib/api.js';

const peso = (n) => `₱${Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function Stat({ icon, label, value, to }) {
    const inner = (
        <>
            <div className="tf-stat-icon">
                <i className={`bi ${icon}`} />
            </div>
            <div>
                <div className="tf-stat-value fare-amount">{value ?? '—'}</div>
                <div className="tf-stat-label">{label}</div>
            </div>
            {to && <i className="bi bi-chevron-right ms-auto text-muted" />}
        </>
    );
    return to ? (
        <Link to={to} className="tf-stat text-reset text-decoration-none">{inner}</Link>
    ) : (
        <div className="tf-stat">{inner}</div>
    );
}

/** Minimal 7-day collection bar chart, no chart library. */
function WeeklyChart({ series }) {
    const max = Math.max(1, ...series.map((d) => d.collected));
    return (
        <div className="d-flex align-items-end gap-2" style={{ height: 120 }}>
            {series.map((d) => (
                <div key={d.date} className="flex-fill text-center d-flex flex-column justify-content-end" title={peso(d.collected)}>
                    <div
                        className="rounded-top"
                        style={{
                            height: `${Math.round((d.collected / max) * 96)}px`,
                            minHeight: 2,
                            background: 'var(--tf-accent, #f5a623)',
                        }}
                    />
                    <div className="small text-muted mt-1">{d.label}</div>
                </div>
            ))}
        </div>
    );
}

export default function Dashboard() {
    const { user, isSuperAdmin, can } = useAuth();
    const [superStats, setSuperStats] = useState({});
    const [data, setData] = useState(null);
    const hasCompany = !!user?.company_id;

    useEffect(() => {
        if (isSuperAdmin) {
            companies.list({ per_page: 1 })
                .then((r) => setSuperStats((s) => ({ ...s, companies: r.meta?.total })))
                .catch(() => {});
        }
        if (hasCompany && can('dashboard.view')) {
            dashboard.get().then(setData).catch(() => {});
        }
    }, [isSuperAdmin, hasCompany, can]);

    const today = data?.today;
    const recent = data?.recent_trips ?? [];
    const weekly = useMemo(() => data?.weekly_collection ?? [], [data]);

    return (
        <>
            <PageHeader
                eyebrow={user?.company ? 'Company Console' : 'Platform Console'}
                title={`Welcome, ${user?.name?.split(' ')[0] ?? ''}`}
                subtitle={user?.company ? user.company.name : 'TransitFlow platform'}
            />

            <div className="row g-3 mb-4">
                {isSuperAdmin && (
                    <div className="col-sm-6 col-xl-3">
                        <Stat icon="bi-buildings" label="Companies" value={superStats.companies} to="/companies" />
                    </div>
                )}
                {today && (
                    <>
                        <div className="col-sm-6 col-xl-3">
                            <Stat icon="bi-bus-front-fill" label="Trips today" value={today.trips} />
                        </div>
                        <div className="col-sm-6 col-xl-3">
                            <Stat icon="bi-ticket-perforated" label="Tickets today" value={today.tickets} />
                        </div>
                        <div className="col-sm-6 col-xl-3">
                            <Stat icon="bi-cash-coin" label="Collected today" value={peso(today.collected)} />
                        </div>
                    </>
                )}
                {data && (
                    <>
                        <div className="col-sm-6 col-xl-3">
                            <Stat icon="bi-bus-front" label="Active buses" value={`${data.buses.active} / ${data.buses.total}`} to="/company/buses" />
                        </div>
                        <div className="col-sm-6 col-xl-3">
                            <Stat icon="bi-people" label="Active accounts" value={`${data.accounts.active} / ${data.accounts.total}`} to="/company/users" />
                        </div>
                        <div className="col-sm-6 col-xl-3">
                            <Stat icon="bi-person-badge" label="Drivers" value={`${data.drivers.active} / ${data.drivers.total}`} to="/company/drivers" />
                        </div>
                        <div className="col-sm-6 col-xl-3">
                            <Stat icon="bi-signpost-split" label="Routes priced" value={`${data.routes.active} / ${data.routes.total}`} />
                        </div>
                    </>
                )}
            </div>

            {data && (
                <div className="row g-3 mb-4">
                    <div className="col-lg-7">
                        <div className="card h-100">
                            <div className="card-body">
                                <h2 className="h6 tf-eyebrow text-uppercase" style={{ color: 'var(--tf-muted)' }}>
                                    Collection — last 7 days
                                </h2>
                                <WeeklyChart series={weekly} />
                            </div>
                        </div>
                    </div>
                    <div className="col-lg-5">
                        <div className="card h-100">
                            <div className="card-body">
                                <h2 className="h6 tf-eyebrow text-uppercase" style={{ color: 'var(--tf-muted)' }}>
                                    Today by payment method
                                </h2>
                                <table className="table table-sm mb-0">
                                    <tbody>
                                        {(data.today_by_method ?? []).map((m) => (
                                            <tr key={m.method}>
                                                <td>{m.method}</td>
                                                <td className="text-end text-muted small">{m.tickets} tickets</td>
                                                <td className="text-end fw-semibold">{peso(m.collected)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {recent.length > 0 && (
                <div className="card mb-4">
                    <div className="card-header bg-white fw-semibold">Recent trips</div>
                    <div className="table-responsive">
                        <table className="table table-sm table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Reference</th><th>Bus</th><th>Conductor</th><th>Coverage</th>
                                    <th>Status</th><th className="text-end">Tickets</th><th className="text-end">Collected</th>
                                </tr>
                            </thead>
                            <tbody>
                                {recent.map((t) => (
                                    <tr key={t.id}>
                                        <td className="font-monospace small">{t.reference}</td>
                                        <td>{t.bus_number}</td>
                                        <td className="small">{t.conductor_name ?? '—'}</td>
                                        <td className="small">{t.origin}{t.destination ? ` → ${t.destination}` : ''}</td>
                                        <td><span className="badge text-bg-light">{t.status}</span></td>
                                        <td className="text-end">{t.ticket_count}</td>
                                        <td className="text-end fw-semibold">{peso(t.collected)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            <div className="card">
                <div className="card-body">
                    <h2 className="h6 text-uppercase tf-eyebrow" style={{ color: 'var(--tf-muted)' }}>
                        Your access
                    </h2>
                    <p className="small text-muted mb-2">
                        <span className="badge tf-badge-active text-capitalize me-1">{user?.role?.replace(/_/g, ' ')}</span>
                        {user?.access_role && <>· {user.access_role.name}</>}
                    </p>
                    <div className="d-flex flex-wrap gap-1">
                        {isSuperAdmin ? (
                            <span className="badge tf-badge-active">Full platform access</span>
                        ) : (
                            (user?.permissions ?? []).map((p) => (
                                <code key={p} className="badge border bg-body-tertiary text-body">
                                    {p}
                                </code>
                            ))
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
