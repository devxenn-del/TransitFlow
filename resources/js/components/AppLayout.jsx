import { useState } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { swal as Swal } from '../lib/ui.js';

import { useAuth } from '../auth/AuthContext.jsx';
import { auth as authApi, voidPin as voidPinApi } from '../lib/api.js';
import { errorMessage, notifySuccess } from '../lib/ui.js';

const pinFields = (labels) => labels.map((p) => `<input id="${p.id}" type="password" ${p.numeric ? 'inputmode="numeric"' : ''} class="swal2-input" placeholder="${p.ph}">`).join('');

async function changePasswordDialog() {
    const { isConfirmed, value } = await Swal.fire({
        title: 'Change password',
        html: pinFields([
            { id: 'cur', ph: 'Current password' },
            { id: 'p1', ph: 'New password (min 8)' },
            { id: 'p2', ph: 'Confirm new password' },
        ]),
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Update password',
        preConfirm: () => {
            const cur = document.getElementById('cur').value;
            const p1 = document.getElementById('p1').value;
            const p2 = document.getElementById('p2').value;
            if (!cur || !p1) return Swal.showValidationMessage('All fields are required');
            if (p1.length < 8) return Swal.showValidationMessage('New password must be at least 8 characters');
            if (p1 !== p2) return Swal.showValidationMessage('Passwords do not match');
            return { current_password: cur, password: p1, password_confirmation: p2 };
        },
    });
    if (!isConfirmed) return;
    try { await authApi.changePassword(value); notifySuccess('Password updated.'); }
    catch (e) { Swal.fire({ icon: 'error', text: errorMessage(e, 'Could not update your password.') }); }
}

async function voidPinDialog(hasPin) {
    const { isConfirmed, value } = await Swal.fire({
        title: hasPin ? 'Change your void PIN' : 'Set your void PIN',
        html: pinFields([
            { id: 'cur', ph: 'Current password' },
            { id: 'p1', ph: 'New PIN (4–8 digits)', numeric: true },
            { id: 'p2', ph: 'Confirm PIN', numeric: true },
        ]),
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Save PIN',
        preConfirm: () => {
            const cur = document.getElementById('cur').value;
            const p1 = document.getElementById('p1').value;
            const p2 = document.getElementById('p2').value;
            if (!cur || !p1) return Swal.showValidationMessage('All fields are required');
            if (!/^\d{4,8}$/.test(p1)) return Swal.showValidationMessage('PIN must be 4–8 digits');
            if (p1 !== p2) return Swal.showValidationMessage('PINs do not match');
            return { current_password: cur, pin: p1, pin_confirmation: p2 };
        },
    });
    if (!isConfirmed) return;
    try { await voidPinApi.set(value); notifySuccess('Void PIN saved.'); }
    catch (e) { Swal.fire({ icon: 'error', text: errorMessage(e, 'Could not save your void PIN.') }); }
}

async function appPinDialog(hasPin) {
    const { isConfirmed, value } = await Swal.fire({
        title: hasPin ? 'Change your App PIN' : 'Set your App PIN',
        html: pinFields([
            { id: 'cur', ph: 'Current password' },
            { id: 'p1', ph: 'New PIN (4 digits)', numeric: true },
            { id: 'p2', ph: 'Confirm PIN', numeric: true },
        ]),
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Save PIN',
        preConfirm: () => {
            const cur = document.getElementById('cur').value;
            const p1 = document.getElementById('p1').value;
            const p2 = document.getElementById('p2').value;
            if (!cur || !p1) return Swal.showValidationMessage('All fields are required');
            if (!/^\d{4}$/.test(p1)) return Swal.showValidationMessage('PIN must be exactly 4 digits');
            if (p1 !== p2) return Swal.showValidationMessage('PINs do not match');
            return { current_password: cur, pin: p1, pin_confirmation: p2 };
        },
    });
    if (!isConfirmed) return;
    try { await authApi.setPin(value); notifySuccess('App PIN saved.'); }
    catch (e) { Swal.fire({ icon: 'error', text: errorMessage(e, 'Could not save your App PIN.') }); }
}

/** Sidebar entries; each shown only when `visible(auth)` is true. */
const NAV = [
    { to: '/', label: 'Dashboard', icon: 'bi-speedometer2', end: true, visible: () => true },
    { to: '/conductor/trips', label: 'My Trips', icon: 'bi-bus-front-fill', visible: (a) => a.can('trips.view') },
    {
        section: 'Platform',
        visible: (a) => a.isSuperAdmin,
        items: [
            { to: '/companies', label: 'Companies', icon: 'bi-buildings', visible: (a) => a.can('companies.view') },
            { to: '/platform-users', label: 'Platform Users', icon: 'bi-person-gear', visible: (a) => a.can('platform.users.view') },
            { to: '/super-admin/system-configuration', label: 'System Configuration', icon: 'bi-hdd-network', visible: (a) => a.can('system.configuration.view') },
            { to: '/super-admin/legal-documents', label: 'Legal Documents', icon: 'bi-file-earmark-text', visible: (a) => a.can('legal.view') },
            { to: '/super-admin/mobile-app', label: 'Mobile App', icon: 'bi-google-play', visible: (a) => a.can('mobileapp.manage') },
        ],
    },
    {
        section: 'Fleet',
        visible: (a) => !!a.user?.company_id,
        items: [
            { to: '/company/terminals', label: 'Terminals', icon: 'bi-signpost-split', visible: (a) => a.can('terminals.view') },
            { to: '/company/franchises', label: 'Fare Matrix', icon: 'bi-cash-coin', visible: (a) => a.can('franchises.view') },
            { to: '/company/drivers', label: 'Drivers', icon: 'bi-person-badge', visible: (a) => a.can('drivers.view') },
            { to: '/company/conductors', label: 'Conductors', icon: 'bi-person-vcard', visible: (a) => a.can('conductors.view') },
            { to: '/company/passenger-types', label: 'Passenger Types', icon: 'bi-people-fill', visible: (a) => a.can('passengertypes.view') },
            { to: '/company/buses', label: 'Buses', icon: 'bi-bus-front', visible: (a) => a.can('buses.view') },
            { to: '/company/thermal-printers', label: 'Thermal Printers', icon: 'bi-printer-fill', visible: (a) => a.can('thermalprinters.view') },
            { to: '/company/admin-assignments', label: 'Admin Assignments', icon: 'bi-person-lines-fill', visible: (a) => a.can('adminassignments.view') },
        ],
    },
    {
        section: 'Operations',
        visible: (a) => a.can('tracking.view') || a.can('tripmonitoring.view') || a.can('remittances.view') || a.can('attendance.view') || a.can('cashcount.view') || a.can('expenses.view') || a.can('fuel.view'),
        items: [
            { to: '/company/live', label: 'Live Monitor', icon: 'bi-broadcast-pin', visible: (a) => a.can('tracking.view') },
            { to: '/company/trip-monitor', label: 'Trip Monitoring', icon: 'bi-clipboard-data', visible: (a) => a.can('tripmonitoring.view') },
            { to: '/company/remittances', label: 'Remittances', icon: 'bi-cash-coin', visible: (a) => a.can('remittances.view') },
            { to: '/company/cash-counts', label: 'Cash Count', icon: 'bi-cash-stack', visible: (a) => a.can('cashcount.view') },
            { to: '/company/expenses', label: 'Expenses', icon: 'bi-receipt-cutoff', visible: (a) => a.can('expenses.view') },
            { to: '/company/fuel', label: 'Fuel & Energy', icon: 'bi-fuel-pump', visible: (a) => a.can('fuel.view') },
            { to: '/company/attendance', label: 'Attendance', icon: 'bi-clock-history', visible: (a) => a.can('attendance.view') && !a.can('trips.view') },
        ],
    },
    {
        section: 'Reports & Analytics',
        visible: (a) => a.can('reports.view') || a.can('dailyops.view') || a.can('expensereport.view') || a.can('cashcountreport.view') || a.can('fuelenergyreport.view'),
        items: [
            { to: '/company/reports/income', label: 'Income Monitoring', icon: 'bi-graph-up-arrow', visible: (a) => a.can('reports.view') },
            { to: '/company/reports/daily-operations', label: 'Daily Operations', icon: 'bi-clipboard-data', visible: (a) => a.can('dailyops.view') },
            { to: '/company/reports/expenses', label: 'Expense Report', icon: 'bi-receipt', visible: (a) => a.can('expensereport.view') },
            { to: '/company/reports/cash-count', label: 'Cash Count Report', icon: 'bi-cash-stack', visible: (a) => a.can('cashcountreport.view') },
            { to: '/company/reports/fuel-energy', label: 'Fuel & Energy Report', icon: 'bi-fuel-pump', visible: (a) => a.can('fuelenergyreport.view') },
        ],
    },
    {
        section: 'Company',
        visible: (a) => !!a.user?.company_id,
        items: [
            { to: '/company/profile', label: 'Company Profile', icon: 'bi-building', visible: (a) => a.can('company.profile.view') },
            { to: '/company/settings', label: 'Settings', icon: 'bi-sliders', visible: (a) => a.can('company.settings.view') || a.can('company.settings.manage') },
            { to: '/company/devices', label: 'Devices', icon: 'bi-phone', visible: (a) => a.can('devices.view') },
            { to: '/company/mobile-app', label: 'Mobile App', icon: 'bi-google-play', visible: (a) => a.can('mobileapp.view') },
            { to: '/company/data-tools', label: 'Data Tools', icon: 'bi-database-gear', visible: (a) => a.can('backup.view') || a.can('backup.download') || a.can('cleandata.run') },
            { to: '/company/audit-log', label: 'Audit Log', icon: 'bi-journal-text', visible: (a) => a.can('audit.view') },
            { to: '/company/users', label: 'Users', icon: 'bi-people', visible: (a) => a.can('accounts.view') },
            { to: '/company/roles', label: 'Roles', icon: 'bi-person-badge-fill', visible: (a) => a.can('roles.view') },
            { to: '/company/void-security', label: 'Void Security', icon: 'bi-shield-lock-fill', visible: (a) => a.can('voidsecurity.view') },
        ],
    },
];

export default function AppLayout() {
    const auth = useAuth();
    const navigate = useNavigate();
    const [open, setOpen] = useState(false);

    const doLogout = async () => {
        await auth.logout();
        navigate('/login', { replace: true });
    };

    const roleLabel = (auth.user?.access_role?.name || auth.user?.role || '').replace(/_/g, ' ');

    const link = (item) =>
        item.visible(auth) ? (
            <NavLink
                key={item.to}
                to={item.to}
                end={item.end}
                className={({ isActive }) => `tf-nav-link${isActive ? ' active' : ''}`}
                onClick={() => setOpen(false)}
            >
                <i className={`bi ${item.icon}`} />
                <span>{item.label}</span>
            </NavLink>
        ) : null;

    return (
        <div className="tf-app">
            <header className="tf-topbar">
                <div className="d-flex align-items-center gap-2">
                    <button className="btn btn-sm btn-outline-light border-0 d-lg-none px-2" onClick={() => setOpen((v) => !v)}>
                        <i className="bi bi-list fs-4" />
                    </button>
                    <span className="tf-brand">
                        <i className="bi bi-bus-front-fill" />
                        <span>
                            {auth.user?.company?.name ?? 'TransitFlow'}
                            <small>{auth.user?.company ? 'Powered by TransitFlow' : 'Smart Transport Mgmt'}</small>
                        </span>
                    </span>
                </div>
                <div className="d-flex align-items-center gap-3">
                    {roleLabel && <span className="tf-role-pill d-none d-sm-inline-block">{roleLabel}</span>}
                    <div className="dropdown">
                        <button className="dropdown-toggle" data-bs-toggle="dropdown">
                            <i className="bi bi-person-circle" />
                            <span className="d-none d-md-inline">{auth.user?.name}</span>
                        </button>
                        <ul className="dropdown-menu dropdown-menu-end">
                            <li className="dropdown-header small">
                                {auth.user?.email}
                                {auth.user?.company && <><br />{auth.user.company.name}</>}
                            </li>
                            <li><hr className="dropdown-divider" /></li>
                            <li>
                                <button className="dropdown-item" onClick={() => navigate('/profile')}>
                                    <i className="bi bi-person me-2" />My profile
                                </button>
                            </li>
                            <li>
                                <button className="dropdown-item" onClick={changePasswordDialog}>
                                    <i className="bi bi-key me-2" />Change password
                                </button>
                            </li>
                            {auth.user?.access_role?.key === 'conductor' && (
                                <li>
                                    <button
                                        className="dropdown-item"
                                        onClick={() => appPinDialog(auth.user?.has_pin).then(() => auth.refreshUser?.())}
                                    >
                                        <i className="bi bi-grid-3x3 me-2" />
                                        {auth.user?.has_pin ? 'Change App PIN' : 'Set App PIN'}
                                    </button>
                                </li>
                            )}
                            {auth.can('voidpin.manage') && (
                                <li>
                                    <button
                                        className="dropdown-item"
                                        onClick={() => voidPinDialog(auth.user?.has_void_pin).then(() => auth.refreshUser?.())}
                                    >
                                        <i className="bi bi-shield-lock me-2" />
                                        {auth.user?.has_void_pin ? 'Change void PIN' : 'Set void PIN'}
                                    </button>
                                </li>
                            )}
                            <li><hr className="dropdown-divider" /></li>
                            <li>
                                <button className="dropdown-item text-danger" onClick={doLogout}>
                                    <i className="bi bi-box-arrow-right me-2" />Sign out
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
            </header>

            <div className="tf-body">
                <div className={`tf-sidebar-backdrop ${open ? 'show' : ''}`} onClick={() => setOpen(false)} />
                <aside className={`tf-sidebar ${open ? 'open' : ''}`}>
                    <div className="tf-signage">
                        <div className="tf-signage-row">
                            <i className="bi bi-bus-front-fill tf-signage-icon" />
                            <div>
                                <p className="tf-signage-title">TRANSITFLOW</p>
                                <div className="tf-signage-sub">{auth.user?.company?.name ?? 'Platform Console'}</div>
                            </div>
                        </div>
                    </div>

                    <nav className="tf-nav-wrap">
                        {NAV.map((entry) =>
                            entry.section ? (
                                entry.visible(auth) && entry.items.some((i) => i.visible(auth)) ? (
                                    <div key={entry.section}>
                                        <div className="tf-nav-section-label">{entry.section}</div>
                                        {entry.items.map(link)}
                                    </div>
                                ) : null
                            ) : (
                                link(entry)
                            ),
                        )}
                    </nav>

                    <div className="tf-sidebar-footer">
                        <button className="tf-logout-btn" onClick={doLogout}>
                            <i className="bi bi-box-arrow-right" />Sign out
                        </button>
                    </div>
                </aside>

                <main className="tf-content">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}
