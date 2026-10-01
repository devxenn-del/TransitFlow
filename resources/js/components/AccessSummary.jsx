import { useMemo, useState } from 'react';

/**
 * The signed-in user's permissions, grouped into modules and areas with
 * plain-language actions — instead of a wall of raw `module.action` keys.
 */

/** Module (permission-key prefix) → label, icon and area. Unknown prefixes land in "Other". */
const MODULES = {
    'company.profile': { label: 'Company Profile', icon: 'bi-building', area: 'Company' },
    'company.settings': { label: 'Company Settings', icon: 'bi-sliders', area: 'Company' },
    accounts: { label: 'User Accounts', icon: 'bi-people', area: 'Company' },
    roles: { label: 'Roles', icon: 'bi-person-badge-fill', area: 'Company' },
    permissions: { label: 'Permissions', icon: 'bi-shield-lock', area: 'Company' },
    documents: { label: 'Documents', icon: 'bi-folder2-open', area: 'Company' },
    devices: { label: 'Devices', icon: 'bi-phone', area: 'Company' },
    mobileapp: { label: 'Mobile App', icon: 'bi-google-play', area: 'Company' },

    buses: { label: 'Buses', icon: 'bi-bus-front', area: 'Fleet' },
    drivers: { label: 'Drivers', icon: 'bi-person-badge', area: 'Fleet' },
    conductors: { label: 'Conductors', icon: 'bi-person-vcard', area: 'Fleet' },
    terminals: { label: 'Terminals', icon: 'bi-signpost-split', area: 'Fleet' },
    routes: { label: 'Routes', icon: 'bi-signpost-2', area: 'Fleet' },
    franchises: { label: 'Franchises', icon: 'bi-file-earmark-text', area: 'Fleet' },
    farematrix: { label: 'Fare Matrix', icon: 'bi-cash-coin', area: 'Fleet' },
    passengertypes: { label: 'Passenger Types', icon: 'bi-people-fill', area: 'Fleet' },
    thermalprinters: { label: 'Thermal Printers', icon: 'bi-printer-fill', area: 'Fleet' },

    tracking: { label: 'Live Tracking', icon: 'bi-broadcast-pin', area: 'Operations' },
    tripmonitoring: { label: 'Trip Monitoring', icon: 'bi-clipboard-data', area: 'Operations' },
    trips: { label: 'Trips', icon: 'bi-bus-front-fill', area: 'Operations' },
    tickets: { label: 'Tickets', icon: 'bi-ticket-perforated', area: 'Operations' },
    dispatch: { label: 'Dispatch', icon: 'bi-megaphone', area: 'Operations' },
    attendance: { label: 'Attendance', icon: 'bi-clock-history', area: 'Operations' },
    fuel: { label: 'Fuel', icon: 'bi-fuel-pump', area: 'Operations' },
    charging: { label: 'EV Charging', icon: 'bi-ev-station', area: 'Operations' },

    dashboard: { label: 'Dashboard', icon: 'bi-speedometer2', area: 'Reports & Data' },
    reports: { label: 'Income Reports', icon: 'bi-graph-up-arrow', area: 'Reports & Data' },
    fuelenergyreport: { label: 'Fuel & Energy Report', icon: 'bi-bar-chart-line', area: 'Reports & Data' },
    audit: { label: 'Audit Log', icon: 'bi-journal-text', area: 'Reports & Data' },
    backup: { label: 'Data Backup', icon: 'bi-database-down', area: 'Reports & Data' },
    cleandata: { label: 'Data Cleanup', icon: 'bi-eraser', area: 'Reports & Data' },
};

const AREAS = ['Company', 'Fleet', 'Operations', 'Reports & Data', 'Other'];

const ACTION_LABELS = {
    view: 'View', create: 'Create', edit: 'Edit', delete: 'Delete', manage: 'Manage', lock: 'Lock / unlock',
    forceend: 'Force-end', record: 'Record', download: 'Export', run: 'Run', issue: 'Issue', start: 'Start',
    end: 'End', cancel: 'Cancel', markontrip: 'Mark on-trip', clock: 'Clock in/out', ping: 'Report GPS', register: 'Register',
};

/** Actions in a consistent reading order; anything else follows alphabetically. */
const ACTION_ORDER = ['view', 'create', 'edit', 'delete', 'manage'];

function moduleKeyOf(permission) {
    const parts = permission.split('.');
    const twoPart = parts.slice(0, 2).join('.');
    return MODULES[twoPart] ? twoPart : parts[0];
}

function groupPermissions(keys) {
    const modules = new Map();
    keys.forEach((key) => {
        const moduleKey = moduleKeyOf(key);
        const action = key.slice(moduleKey.length + 1) || 'view';
        if (!modules.has(moduleKey)) {
            const meta = MODULES[moduleKey] ?? { label: moduleKey.replace(/[._]/g, ' '), icon: 'bi-grid', area: 'Other' };
            modules.set(moduleKey, { key: moduleKey, ...meta, actions: [] });
        }
        modules.get(moduleKey).actions.push(action);
    });

    modules.forEach((m) => m.actions.sort((a, b) => {
        const ia = ACTION_ORDER.indexOf(a);
        const ib = ACTION_ORDER.indexOf(b);
        if (ia !== -1 || ib !== -1) return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib);
        return a.localeCompare(b);
    }));

    return AREAS
        .map((area) => ({ area, modules: [...modules.values()].filter((m) => m.area === area).sort((a, b) => a.label.localeCompare(b.label)) }))
        .filter((g) => g.modules.length > 0);
}

function isFullCrud(actions) {
    return ['view', 'create', 'edit', 'delete'].every((a) => actions.includes(a));
}

export default function AccessSummary({ user, isSuperAdmin }) {
    const [expanded, setExpanded] = useState(false);
    const groups = useMemo(() => groupPermissions(user?.permissions ?? []), [user?.permissions]);
    const moduleCount = groups.reduce((n, g) => n + g.modules.length, 0);
    const roleName = user?.access_role?.name ?? user?.role?.replace(/_/g, ' ');

    return (
        <section className="card tf-access">
            <div className="tf-access-head">
                <div className="tf-access-icon"><i className="bi bi-shield-check" /></div>
                <div className="flex-grow-1 min-w-0">
                    <h2 className="tf-access-title">Your access</h2>
                    <div className="small text-body-secondary">
                        Signed in as <span className="fw-semibold text-body text-capitalize">{roleName}</span>
                        {user?.company?.name && <> at {user.company.name}</>}
                    </div>
                </div>
                {!isSuperAdmin && moduleCount > 0 && (
                    <div className="tf-access-stats">
                        <div><strong>{moduleCount}</strong><span>modules</span></div>
                        <div><strong>{user.permissions.length}</strong><span>permissions</span></div>
                    </div>
                )}
            </div>

            {isSuperAdmin ? (
                <div className="tf-access-body">
                    <div className="tf-access-full">
                        <i className="bi bi-stars" />
                        <div>
                            <div className="fw-semibold">Full platform access</div>
                            <div className="small text-body-secondary">As Super Admin you can manage every company, user and module on TransitFlow.</div>
                        </div>
                    </div>
                </div>
            ) : moduleCount === 0 ? (
                <div className="tf-access-body small text-body-secondary">
                    No permissions are assigned to your account yet. Ask your company administrator for access.
                </div>
            ) : (
                <>
                    <div className={`tf-access-body${expanded ? '' : ' is-collapsed'}`}>
                        {groups.map((group) => (
                            <div key={group.area} className="tf-access-area">
                                <div className="tf-access-area-label">{group.area}</div>
                                <div className="tf-access-grid">
                                    {group.modules.map((m) => (
                                        <div key={m.key} className="tf-access-module">
                                            <i className={`bi ${m.icon} tf-access-module-icon`} />
                                            <div className="min-w-0">
                                                <div className="tf-access-module-name">
                                                    {m.label}
                                                    {isFullCrud(m.actions) && <span className="tf-access-full-pill">Full access</span>}
                                                </div>
                                                <div className="tf-access-actions">
                                                    {m.actions.map((a) => (
                                                        <span key={a} className="tf-access-action">{ACTION_LABELS[a] ?? a.replace(/[._]/g, ' ')}</span>
                                                    ))}
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                    <button type="button" className="tf-access-toggle" onClick={() => setExpanded((v) => !v)} aria-expanded={expanded}>
                        {expanded ? 'Show less' : `Show all ${moduleCount} modules`}
                        <i className={`bi ${expanded ? 'bi-chevron-up' : 'bi-chevron-down'} ms-1`} />
                    </button>
                </>
            )}
        </section>
    );
}
