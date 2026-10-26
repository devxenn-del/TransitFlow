import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';

import { useAuth } from '../auth/AuthContext.jsx';
import AccessSummary from '../components/AccessSummary.jsx';
import { companies, dashboard } from '../lib/api.js';
import { SkeletonAccessSummary, SkeletonCard, SkeletonPageHeader, SkeletonStats } from '../components/Skeleton.jsx';

const peso = (n) => `₱${Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const pesoShort = (n) => `₱${Number(n ?? 0).toLocaleString(undefined, { notation: 'compact', maximumFractionDigits: 1 })}`;

const TRIP_STATUS = {
    Departure: { cls: 'is-departure', icon: 'bi-box-arrow-right', label: 'Departure' },
    OnTrip: { cls: 'is-ontrip', icon: 'bi-broadcast', label: 'On trip' },
    Arrived: { cls: 'is-arrived', icon: 'bi-check2-circle', label: 'Arrived' },
    Cancelled: { cls: 'is-cancelled', icon: 'bi-x-circle', label: 'Cancelled' },
};

const METHOD_ICONS = { Cash: 'bi-cash-stack', QR: 'bi-qr-code' };

function greeting() {
    const hour = new Date().getHours();
    if (hour < 12) {
        return 'Good morning';
    }
    return hour < 18 ? 'Good afternoon' : 'Good evening';
}

function timeOf(dateTime) {
    if (!dateTime) {
        return '—';
    }
    const date = new Date(dateTime.replace(' ', 'T'));
    const isToday = date.toDateString() === new Date().toDateString();
    const time = date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    return isToday ? time : `${date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}, ${time}`;
}

/** Today's headline figure; `delta` is a % change against yesterday. */
function KpiCard({ icon, label, value, hint, delta, tone = 'navy' }) {
    return (
        <div className={`tf-kpi tf-kpi--${tone}`}>
            <div className="tf-kpi-top">
                <span className="tf-kpi-label">{label}</span>
                <span className="tf-kpi-icon"><i className={`bi ${icon}`} /></span>
            </div>
            <div className="tf-kpi-value fare-amount">{value ?? '—'}</div>
            <div className="tf-kpi-foot">
                {delta != null && (
                    <span className={`tf-delta ${delta >= 0 ? 'is-up' : 'is-down'}`}>
                        <i className={`bi ${delta >= 0 ? 'bi-arrow-up-right' : 'bi-arrow-down-right'}`} />
                        {Math.abs(delta).toFixed(0)}%
                    </span>
                )}
                {hint && <span className="tf-kpi-hint">{hint}</span>}
            </div>
        </div>
    );
}

/** Fleet/resource counter with an active-vs-total meter. */
function FleetCard({ icon, label, active, total, to }) {
    const pct = total > 0 ? Math.round((active / total) * 100) : 0;
    const inner = (
        <>
            <div className="d-flex align-items-center gap-2 mb-2">
                <span className="tf-fleet-icon"><i className={`bi ${icon}`} /></span>
                <span className="tf-fleet-label">{label}</span>
                {to && <i className="bi bi-arrow-right tf-fleet-go ms-auto" />}
            </div>
            <div className="d-flex align-items-baseline gap-1">
                <span className="tf-fleet-value fare-amount">{active}</span>
                <span className="text-muted small">/ {total} active</span>
            </div>
            <div className="tf-meter mt-2" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100} aria-label={`${label} active`}>
                <span style={{ width: `${pct}%` }} />
            </div>
        </>
    );
    return to ? (
        <Link to={to} className="tf-fleet text-reset text-decoration-none">{inner}</Link>
    ) : (
        <div className="tf-fleet">{inner}</div>
    );
}

/** 7-day collection bar chart with gridlines and today highlighted, no chart library. */
function WeeklyChart({ series }) {
    const max = Math.max(1, ...series.map((d) => d.collected));
    const lastIndex = series.length - 1;
    return (
        <div className="tf-chart">
            <div className="tf-chart-grid" aria-hidden="true">
                <span data-label={pesoShort(max)} />
                <span data-label={pesoShort(max / 2)} />
                <span data-label="₱0" />
            </div>
            <div className="tf-chart-bars">
                {series.map((d, i) => (
                    <div key={d.date} className={`tf-chart-col${i === lastIndex ? ' is-today' : ''}`}>
                        <div className="tf-chart-bar-wrap">
                            <div className="tf-chart-tip fare-amount">{peso(d.collected)}</div>
                            <div
                                className="tf-chart-bar"
                                style={{ height: `${Math.max(1.5, (d.collected / max) * 100)}%` }}
                                aria-label={`${d.label}: ${peso(d.collected)}`}
                            />
                        </div>
                        <div className="tf-chart-label">{i === lastIndex ? 'Today' : d.label}</div>
                    </div>
                ))}
            </div>
        </div>
    );
}

/** Today's collection split by payment method, as one proportional bar + legend rows. */
function MethodBreakdown({ methods }) {
    const total = methods.reduce((sum, m) => sum + m.collected, 0);
    if (total === 0) {
        return (
            <div className="tf-dash-empty">
                <i className="bi bi-wallet2" />
                <div>No collections yet today</div>
            </div>
        );
    }
    return (
        <>
            <div className="tf-split mb-3">
                {methods.map((m, i) => (
                    <span key={m.method} className={`tf-split-seg is-${i}`} style={{ width: `${(m.collected / total) * 100}%` }} />
                ))}
            </div>
            <ul className="tf-method-list">
                {methods.map((m, i) => (
                    <li key={m.method}>
                        <span className={`tf-method-dot is-${i}`} />
                        <i className={`bi ${METHOD_ICONS[m.method] ?? 'bi-credit-card'} text-muted`} />
                        <span className="fw-medium">{m.method}</span>
                        <span className="text-muted small">{m.tickets} tickets · {Math.round((m.collected / total) * 100)}%</span>
                        <span className="ms-auto fw-semibold fare-amount">{peso(m.collected)}</span>
                    </li>
                ))}
            </ul>
        </>
    );
}

function TripStatus({ value }) {
    const meta = TRIP_STATUS[value] ?? { cls: '', icon: 'bi-dot', label: value };
    return (
        <span className={`tf-trip-status ${meta.cls}`}>
            <i className={`bi ${meta.icon}`} /> {meta.label}
        </span>
    );
}

export default function Dashboard() {
    const { user, isSuperAdmin, can } = useAuth();
    const [superStats, setSuperStats] = useState({});
    const [data, setData] = useState(null);
    const [loadingDashboard, setLoadingDashboard] = useState(() => !!user?.company_id && can('dashboard.view'));
    const [loadingCompanies, setLoadingCompanies] = useState(isSuperAdmin);
    const hasCompany = !!user?.company_id;

    useEffect(() => {
        if (isSuperAdmin) {
            setLoadingCompanies(true);
            companies.list({ per_page: 1 })
                .then((r) => setSuperStats((s) => ({ ...s, companies: r.meta?.total })))
                .catch(() => {})
                .finally(() => setLoadingCompanies(false));
        }
        if (hasCompany && can('dashboard.view')) {
            setLoadingDashboard(true);
            dashboard.get().then(setData).catch(() => {}).finally(() => setLoadingDashboard(false));
        }
    }, [isSuperAdmin, hasCompany, can]);

    const today = data?.today;
    const recent = data?.recent_trips ?? [];
    const weekly = useMemo(() => data?.weekly_collection ?? [], [data]);
    const weekTotal = useMemo(() => weekly.reduce((sum, d) => sum + d.collected, 0), [weekly]);
    const yesterday = weekly.length > 1 ? weekly[weekly.length - 2].collected : null;
    const collectedDelta = today && yesterday ? ((today.collected - yesterday) / yesterday) * 100 : null;
    const averageFare = today?.tickets ? today.collected / today.tickets : null;
    const pageLoading = loadingDashboard || loadingCompanies;

    const quickLinks = [
        hasCompany && can('tracking.view') && { to: '/company/live', icon: 'bi-broadcast-pin', label: 'Live monitor' },
        hasCompany && can('tripmonitoring.view') && { to: '/company/trip-monitor', icon: 'bi-clipboard-data', label: 'Trip monitor' },
        hasCompany && can('reports.view') && { to: '/company/reports/income', icon: 'bi-graph-up-arrow', label: 'Income report' },
        isSuperAdmin && { to: '/companies', icon: 'bi-buildings', label: 'Companies' },
    ].filter(Boolean);

    return (
        <>
            {pageLoading ? (
                <SkeletonPageHeader />
            ) : (
                <section className="tf-hero mb-4">
                    <div className="tf-hero-body">
                        <span className="tf-hero-eyebrow">{user?.company ? 'Company Console' : 'Platform Console'}</span>
                        <h1 className="tf-hero-title">{greeting()}, {user?.name?.split(' ')[0] ?? ''}</h1>
                        <div className="tf-hero-sub">
                            <i className="bi bi-calendar3 me-1" />
                            {new Date().toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })}
                            <span className="mx-2 opacity-50">•</span>
                            {user?.company ? user.company.name : 'TransitFlow platform'}
                        </div>
                    </div>
                    {quickLinks.length > 0 && (
                        <div className="tf-hero-actions">
                            {quickLinks.map((l) => (
                                <Link key={l.to} to={l.to} className="tf-hero-link">
                                    <i className={`bi ${l.icon}`} /> {l.label}
                                </Link>
                            ))}
                        </div>
                    )}
                </section>
            )}

            {isSuperAdmin && (
                <div className="row g-3 mb-4">
                    {loadingCompanies ? <SkeletonStats count={1} /> : (
                        <div className="col-sm-6 col-xl-3">
                            <Link to="/companies" className="text-reset text-decoration-none d-block h-100 tf-kpi-link">
                                <KpiCard icon="bi-buildings" label="Companies" value={superStats.companies} hint="on the platform" />
                            </Link>
                        </div>
                    )}
                </div>
            )}

            {loadingDashboard && (
                <>
                    <div className="row g-3 mb-4"><SkeletonStats count={3} colClassName="col-md-4" /></div>
                    <div className="row g-3 mb-4"><SkeletonStats count={4} /></div>
                    <div className="row g-3 mb-4">
                        <div className="col-lg-7"><SkeletonCard lines={5} className="h-100" /></div>
                        <div className="col-lg-5"><SkeletonCard lines={4} className="h-100" /></div>
                    </div>
                </>
            )}

            {data && (
                <>
                    <h2 className="tf-section-title">Today at a glance</h2>
                    <div className="row g-3 mb-4">
                        <div className="col-md-4">
                            <KpiCard icon="bi-bus-front-fill" label="Trips today" value={today.trips} hint="started since midnight" />
                        </div>
                        <div className="col-md-4">
                            <KpiCard
                                icon="bi-ticket-perforated"
                                label="Tickets sold"
                                value={today.tickets.toLocaleString()}
                                hint={averageFare != null ? `avg ${peso(averageFare)} per ticket` : 'no tickets yet'}
                                tone="blue"
                            />
                        </div>
                        <div className="col-md-4">
                            <KpiCard
                                icon="bi-cash-coin"
                                label="Collected today"
                                value={peso(today.collected)}
                                delta={collectedDelta}
                                hint={yesterday ? 'vs yesterday' : 'net of refunds'}
                                tone="accent"
                            />
                        </div>
                    </div>

                    <h2 className="tf-section-title">Fleet &amp; resources</h2>
                    <div className="row g-3 mb-4">
                        <div className="col-sm-6 col-xl-3">
                            <FleetCard icon="bi-bus-front" label="Buses" {...data.buses} to={can('buses.view') ? '/company/buses' : null} />
                        </div>
                        <div className="col-sm-6 col-xl-3">
                            <FleetCard icon="bi-person-badge" label="Drivers" {...data.drivers} to={can('drivers.view') ? '/company/drivers' : null} />
                        </div>
                        <div className="col-sm-6 col-xl-3">
                            <FleetCard icon="bi-signpost-split" label="Routes priced" {...data.routes} to={can('routes.view') ? '/company/routes' : null} />
                        </div>
                        <div className="col-sm-6 col-xl-3">
                            <FleetCard icon="bi-people" label="Accounts" {...data.accounts} to={can('accounts.view') ? '/company/users' : null} />
                        </div>
                    </div>

                    <div className="row g-3 mb-4">
                        <div className="col-lg-7">
                            <div className="card h-100">
                                <div className="card-body">
                                    <div className="d-flex align-items-start justify-content-between gap-3 mb-3">
                                        <div>
                                            <div className="tf-card-title">Collection — last 7 days</div>
                                            <div className="tf-card-figure fare-amount">{peso(weekTotal)}</div>
                                        </div>
                                        <div className="text-end">
                                            <div className="tf-card-title">Daily average</div>
                                            <div className="fw-semibold fare-amount">{peso(weekTotal / Math.max(1, weekly.length))}</div>
                                        </div>
                                    </div>
                                    <WeeklyChart series={weekly} />
                                </div>
                            </div>
                        </div>
                        <div className="col-lg-5">
                            <div className="card h-100">
                                <div className="card-body">
                                    <div className="tf-card-title mb-3">Today by payment method</div>
                                    <MethodBreakdown methods={data.today_by_method ?? []} />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="card mb-4 overflow-hidden">
                        <div className="card-header d-flex align-items-center justify-content-between py-3">
                            <span className="tf-card-title mb-0">Recent trips</span>
                            {can('tripmonitoring.view') && (
                                <Link to="/company/trip-monitor" className="small fw-medium">
                                    View all <i className="bi bi-arrow-right" />
                                </Link>
                            )}
                        </div>
                        {recent.length === 0 ? (
                            <div className="tf-dash-empty py-5">
                                <i className="bi bi-signpost-2" />
                                <div>No trips recorded yet</div>
                            </div>
                        ) : (
                            <div className="table-responsive">
                                <table className="table table-hover align-middle mb-0 tf-recent-trips">
                                    <thead>
                                        <tr>
                                            <th>Trip</th><th>Coverage</th><th>Conductor</th>
                                            <th>Status</th><th className="text-end">Tickets</th><th className="text-end">Collected</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {recent.map((t) => (
                                            <tr key={t.id}>
                                                <td>
                                                    <div className="d-flex align-items-center gap-2">
                                                        <span className="tf-bus-chip">{t.bus_number}</span>
                                                        <div>
                                                            <div className="font-monospace small">{t.reference}</div>
                                                            <div className="text-muted" style={{ fontSize: '0.75rem' }}>{timeOf(t.started_at)}</div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="small">
                                                    {t.origin}
                                                    {t.destination && <><i className="bi bi-arrow-right mx-1 text-muted" />{t.destination}</>}
                                                </td>
                                                <td className="small">{t.conductor_name ?? <span className="text-muted">—</span>}</td>
                                                <td><TripStatus value={t.status} /></td>
                                                <td className="text-end">{t.ticket_count}</td>
                                                <td className="text-end fw-semibold fare-amount">{peso(t.collected)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </>
            )}

            {pageLoading ? <SkeletonAccessSummary /> : <AccessSummary user={user} isSuperAdmin={isSuperAdmin} />}
        </>
    );
}
