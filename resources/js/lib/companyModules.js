/**
 * Every company module, once. The company-user sidebar, the Super Admin's
 * company block in the sidebar, the workspace tab bar and the routes
 * (Root.jsx) are all built from this list, so a page can't exist in one
 * place and be missing from another.
 *
 * `path` is the same under both roots: `/company/<path>` (a company user's
 * own company) and `/companies/:id/<path>` (a company workspace).
 * `visible(auth)` gets the AuthContext value. `primary` modules are tabs in
 * the workspace bar; the rest sit under its "More" menu.
 */

const isConductor = (a) => a.user?.access_role?.key === 'conductor';

export const MODULE_GROUPS = ['Operations', 'Fleet', 'Fares & Routes', 'Reports', 'People & Access', 'Company', 'System'];

export const COMPANY_MODULES = [
    // Operations
    { path: 'live', label: 'Live Monitor', icon: 'bi-broadcast-pin', group: 'Operations', visible: (a) => a.can('tracking.view') },
    { path: 'trip-monitor', label: 'Trip Monitoring', icon: 'bi-clipboard-data', group: 'Operations', primary: true, visible: (a) => a.can('tripmonitoring.view') },
    { path: 'tickets', label: 'Tickets', icon: 'bi-ticket-perforated', group: 'Operations', primary: true, visible: (a) => a.can('tripmonitoring.view') },
    // Conductors clock in from My Trips; this is the office's oversight page.
    { path: 'attendance', label: 'Attendance', icon: 'bi-clock-history', group: 'Operations', visible: (a) => a.can('attendance.view') && !isConductor(a) },
    { path: 'fuel', label: 'Fuel & Energy', icon: 'bi-fuel-pump', group: 'Operations', visible: (a) => a.can('fuel.view') },

    // Fleet
    { path: 'buses', label: 'Buses', icon: 'bi-bus-front', group: 'Fleet', primary: true, visible: (a) => a.can('buses.view') },
    { path: 'drivers', label: 'Drivers', icon: 'bi-person-badge', group: 'Fleet', primary: true, visible: (a) => a.can('drivers.view') },
    { path: 'conductors', label: 'Conductors', icon: 'bi-person-vcard', group: 'Fleet', primary: true, visible: (a) => a.can('conductors.view') },
    { path: 'terminals', label: 'Terminals', icon: 'bi-signpost-split', group: 'Fleet', visible: (a) => a.can('terminals.view') },
    { path: 'thermal-printers', label: 'Thermal Printers', icon: 'bi-printer-fill', group: 'Fleet', visible: (a) => a.can('thermalprinters.view') },

    // Fares & Routes
    { path: 'franchises', label: 'Fare Matrix', icon: 'bi-cash-coin', group: 'Fares & Routes', primary: true, visible: (a) => a.can('franchises.view') },
    { path: 'routes', label: 'Routes', icon: 'bi-signpost-2', group: 'Fares & Routes', visible: (a) => a.can('routes.view') },
    { path: 'passenger-types', label: 'Passenger Types', icon: 'bi-people-fill', group: 'Fares & Routes', visible: (a) => a.can('passengertypes.view') },

    // Reports
    { path: 'reports/income', label: 'Income Monitoring', icon: 'bi-graph-up-arrow', group: 'Reports', primary: true, visible: (a) => a.can('reports.view') },
    { path: 'reports/fuel-energy', label: 'Fuel & Energy Report', icon: 'bi-bar-chart-line', group: 'Reports', visible: (a) => a.can('fuelenergyreport.view') },
    { path: 'audit-log', label: 'Audit Log', icon: 'bi-journal-text', group: 'Reports', primary: true, visible: (a) => a.can('audit.view') },

    // People & Access
    { path: 'users', label: 'Users', icon: 'bi-people', group: 'People & Access', primary: true, visible: (a) => a.can('accounts.view') },
    { path: 'roles', label: 'Roles & Permissions', icon: 'bi-shield-lock', group: 'People & Access', visible: (a) => a.can('roles.view') },

    // Company
    { path: 'profile', label: 'Company Profile', icon: 'bi-building', group: 'Company', visible: (a) => a.can('company.profile.view') },
    { path: 'documents', label: 'Documents', icon: 'bi-folder2-open', group: 'Company', primary: true, visible: (a) => a.can('documents.view') },
    { path: 'billing', label: 'Billing & Fees', icon: 'bi-receipt', group: 'Company', primary: true, visible: (a) => a.can('billing.view') },
    // Super Admin only — a company never sees or sets its own pricing configuration.
    { path: 'pricing', label: 'Pricing Configuration', icon: 'bi-tags', group: 'Company', primary: true, visible: (a) => a.isSuperAdmin && a.can('fees.view') },
    { path: 'settings', label: 'Branding & Receipts', icon: 'bi-sliders', group: 'Company', visible: (a) => a.can('company.settings.view') || a.can('company.settings.manage') },

    // System
    { path: 'devices', label: 'Devices', icon: 'bi-phone', group: 'System', visible: (a) => a.can('devices.view') },
    { path: 'mobile-app', label: 'Mobile App', icon: 'bi-google-play', group: 'System', visible: (a) => a.can('mobileapp.view') },
    { path: 'data-tools', label: 'Data Tools', icon: 'bi-database-gear', group: 'System', visible: (a) => a.can('backup.view') || a.can('backup.download') || a.can('cleandata.run') },
];

/** Workspace-only first tab. */
export const OVERVIEW_MODULE = { path: '', label: 'Overview', icon: 'bi-grid-1x2', group: null, primary: true, visible: () => true };

/** Visible modules, grouped in MODULE_GROUPS order (empty groups dropped). */
export function groupedModules(auth) {
    return MODULE_GROUPS
        .map((group) => ({ group, modules: COMPANY_MODULES.filter((m) => m.group === group && m.visible(auth)) }))
        .filter((g) => g.modules.length > 0);
}

/**
 * The module a sub-path belongs to (longest match wins, so
 * `users/5/permissions` → Users and `fare-matrix/3` → Fare Matrix).
 */
export function moduleForPath(subPath) {
    if (subPath === '' || subPath === '/') return OVERVIEW_MODULE;
    const clean = subPath.replace(/^\/+/, '');
    if (clean.startsWith('fare-matrix')) return COMPANY_MODULES.find((m) => m.path === 'franchises');
    return [...COMPANY_MODULES]
        .sort((a, b) => b.path.length - a.path.length)
        .find((m) => clean === m.path || clean.startsWith(`${m.path}/`)) ?? null;
}
