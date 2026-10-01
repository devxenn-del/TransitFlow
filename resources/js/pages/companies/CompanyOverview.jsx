import { useEffect } from 'react';
import { Link } from 'react-router-dom';

import { useAuth } from '../../auth/AuthContext.jsx';
import StatusBadge from '../../components/StatusBadge.jsx';
import { useCompanyScope } from '../../lib/companyScope.jsx';

const fmt = (n) => (n ?? 0).toLocaleString();
const when = (v) => (v ? new Date(v).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—');
const humanize = (action) => action.replace(/[._]/g, ' ');

function Stat({ icon, label, value, to, hint }) {
    const inner = (
        <>
            <div className="tf-stat-icon"><i className={`bi ${icon}`} /></div>
            <div>
                <div className="tf-stat-value">{fmt(value)}</div>
                <div className="tf-stat-label">{label}</div>
                {hint && <div className="small text-warning-emphasis mt-1">{hint}</div>}
            </div>
            {to && <i className="bi bi-chevron-right ms-auto text-muted" />}
        </>
    );
    return to
        ? <Link to={to} className="tf-stat text-reset text-decoration-none">{inner}</Link>
        : <div className="tf-stat">{inner}</div>;
}

/** A "recently …" card; hidden entirely when the caller can't see that module (list === null). */
function RecentCard({ title, icon, list, to, empty, renderItem }) {
    if (list === null || list === undefined) return null;
    return (
        <div className="col-lg-6">
            <div className="card h-100">
                <div className="card-header bg-transparent d-flex justify-content-between align-items-center">
                    <span className="fw-semibold"><i className={`bi ${icon} me-2 text-body-secondary`} />{title}</span>
                    {to && <Link to={to} className="small text-decoration-none">View all</Link>}
                </div>
                <div className="card-body py-1">
                    {list.length === 0
                        ? <p className="text-body-secondary small text-center my-3">{empty}</p>
                        : <ul className="tf-recent-list">{list.map(renderItem)}</ul>}
                </div>
            </div>
        </div>
    );
}

/** `/companies/:id` — the company at a glance: counts + recent activity. */
export default function CompanyOverview() {
    const { can } = useAuth();
    const { basePath, overview, reload } = useCompanyScope();
    const { stats, recent } = overview;

    // Coming back from another tab: refresh the counts (skip right after the workspace's own first load).
    useEffect(() => {
        if (Date.now() - overview.loadedAt > 2000) reload();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    const link = (path, permission) => (can(permission) ? `${basePath}/${path}` : undefined);

    return (
        <>
            <div className="row g-3 mb-4">
                <div className="col-sm-6 col-xl-3"><Stat icon="bi-people" label="Users" value={stats.users} to={link('users', 'accounts.view')} /></div>
                <div className="col-sm-6 col-xl-3"><Stat icon="bi-person-badge" label="Drivers" value={stats.drivers} to={link('drivers', 'drivers.view')} /></div>
                <div className="col-sm-6 col-xl-3"><Stat icon="bi-person-vcard" label="Conductors" value={stats.conductors} to={link('conductors', 'conductors.view')} /></div>
                <div className="col-sm-6 col-xl-3"><Stat icon="bi-bus-front" label="Buses" value={stats.buses} to={link('buses', 'buses.view')} /></div>
                <div className="col-sm-6 col-xl-3">
                    <Stat
                        icon="bi-folder2-open"
                        label="Documents"
                        value={stats.documents}
                        to={link('documents', 'documents.view')}
                        hint={stats.expiring_documents > 0 ? `${stats.expiring_documents} expired or expiring soon` : null}
                    />
                </div>
                <div className="col-sm-6 col-xl-3"><Stat icon="bi-broadcast-pin" label="Active trips" value={stats.active_trips} to={link('trip-monitor', 'tripmonitoring.view')} /></div>
                <div className="col-sm-6 col-xl-3"><Stat icon="bi-ticket-perforated" label="Total tickets" value={stats.tickets} to={link('tickets', 'tripmonitoring.view')} /></div>
                <div className="col-sm-6 col-xl-3"><Stat icon="bi-calendar-day" label="Tickets today" value={stats.tickets_today} to={link('tickets', 'tripmonitoring.view')} /></div>
            </div>

            <div className="row g-3">
                <RecentCard
                    title="Recently added users"
                    icon="bi-person-plus"
                    list={recent.users}
                    to={`${basePath}/users`}
                    empty="No users yet."
                    renderItem={(u) => (
                        <li key={u.id}>
                            <span className="text-truncate">
                                <Link to={`${basePath}/users/${u.id}`} className="fw-semibold text-reset text-decoration-none">{u.name}</Link>
                                <span className="text-body-secondary ms-2">{u.access_role?.name ?? u.role.replaceAll('_', ' ')}</span>
                            </span>
                            <span className="small text-body-secondary text-nowrap">{when(u.created_at)}</span>
                        </li>
                    )}
                />
                <RecentCard
                    title="Recently uploaded documents"
                    icon="bi-cloud-arrow-up"
                    list={recent.documents}
                    to={`${basePath}/documents`}
                    empty="No documents uploaded yet."
                    renderItem={(d) => (
                        <li key={d.id}>
                            <span className="text-truncate">
                                <span className="fw-semibold">{d.name}</span>
                                <span className="text-body-secondary ms-2">{d.category_label}</span>
                            </span>
                            <span className="small text-body-secondary text-nowrap">{when(d.created_at)}</span>
                        </li>
                    )}
                />
                <RecentCard
                    title="Recently registered buses"
                    icon="bi-bus-front"
                    list={recent.buses}
                    to={`${basePath}/buses`}
                    empty="No buses registered yet."
                    renderItem={(b) => (
                        <li key={b.id}>
                            <span>
                                <span className="fw-semibold">{b.bus_number}</span>
                                <span className="text-body-secondary ms-2">{b.plate_number}</span>
                            </span>
                            <span className="d-flex align-items-center gap-2">
                                <StatusBadge value={b.status.toLowerCase()} />
                                <span className="small text-body-secondary text-nowrap">{when(b.created_at)}</span>
                            </span>
                        </li>
                    )}
                />
                <RecentCard
                    title="Recent trips"
                    icon="bi-signpost-2"
                    list={recent.trips}
                    to={`${basePath}/trip-monitor`}
                    empty="No trips yet."
                    renderItem={(t) => (
                        <li key={t.id}>
                            <span className="text-truncate">
                                <span className="fw-semibold">{t.bus_number ?? '—'}</span>
                                <span className="text-body-secondary ms-2">{t.coverage_origin} → {t.coverage_destination}</span>
                            </span>
                            <span className="d-flex align-items-center gap-2">
                                <StatusBadge value={t.status.toLowerCase()} />
                                <span className="small text-body-secondary text-nowrap">{when(t.started_at)}</span>
                            </span>
                        </li>
                    )}
                />
                <RecentCard
                    title="Recent system activity"
                    icon="bi-journal-text"
                    list={recent.activity}
                    empty="No activity recorded yet."
                    renderItem={(a) => (
                        <li key={a.id}>
                            <span className="text-truncate">
                                <span className="fw-semibold">{a.user_name ?? 'System'}</span>
                                <span className="text-body-secondary ms-2">{humanize(a.action)}{a.subject_label ? ` — ${a.subject_label}` : ''}</span>
                            </span>
                            <span className="small text-body-secondary text-nowrap">{when(a.created_at)}</span>
                        </li>
                    )}
                />
            </div>
        </>
    );
}
