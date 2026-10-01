import { useMemo, useState } from 'react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { swal as Swal } from '../lib/ui.js';

import { useAuth } from '../auth/AuthContext.jsx';
import { auth as authApi } from '../lib/api.js';
import { COMPANY_MODULES, MODULE_GROUPS, OVERVIEW_MODULE, groupedModules } from '../lib/companyModules.js';
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

const isCompanyUser = (a) => !!a.user?.company_id;

/**
 * Sidebar, as sections of links. A section shows when `visible(auth)` is
 * true and at least one of its items is visible; `label: null` renders its
 * items without a heading. Super Admin gets the platform console; company
 * users get their company's modules grouped by job. (A Super Admin works
 * inside a company through its workspace — see workspaceSection() below.)
 */
const NAV = [
    {
        id: 'home',
        label: null,
        visible: () => true,
        items: [
            { to: '/', label: 'Dashboard', icon: 'bi-speedometer2', end: true, visible: () => true },
            { to: '/conductor/trips', label: 'My Trips', icon: 'bi-bus-front-fill', visible: (a) => a.can('trips.view') },
            { to: (a) => `/companies/${a.user.company_id}`, label: 'My Company', icon: 'bi-buildings', visible: (a) => isCompanyUser(a) && a.can('company.profile.view') },
        ],
    },

    // --- Super Admin -------------------------------------------------------
    {
        id: 'tenants',
        label: 'Tenants',
        visible: (a) => a.isSuperAdmin,
        items: [
            { to: '/companies', label: 'Companies', icon: 'bi-buildings', visible: (a) => a.can('companies.view') },
            { to: '/super-admin/fees', label: 'Fee Management', icon: 'bi-cash-stack', visible: (a) => a.can('fees.view') },
        ],
    },
    {
        id: 'platform',
        label: 'Platform',
        visible: (a) => a.isSuperAdmin,
        items: [
            { to: '/platform-users', label: 'Platform Users', icon: 'bi-person-gear', visible: (a) => a.can('platform.users.view') },
            { to: '/super-admin/mobile-app', label: 'Mobile App', icon: 'bi-google-play', visible: (a) => a.can('mobileapp.manage') },
            { to: '/super-admin/legal-documents', label: 'Legal Documents', icon: 'bi-file-earmark-text', visible: (a) => a.can('legal.view') },
        ],
    },
    {
        id: 'platform-system',
        label: 'System',
        visible: (a) => a.isSuperAdmin,
        items: [
            { to: '/super-admin/system-configuration', label: 'System Configuration', icon: 'bi-hdd-network', visible: (a) => a.can('system.configuration.view') },
        ],
    },

    // --- Company users: one section per module group (lib/companyModules.js)
    ...MODULE_GROUPS.map((group) => ({
        id: `company-${group}`,
        label: group,
        visible: isCompanyUser,
        items: COMPANY_MODULES.filter((m) => m.group === group).map((m) => ({
            to: `/company/${m.path}`,
            label: m.label,
            icon: m.icon,
            visible: m.visible,
        })),
    })),
];

/**
 * While a Super Admin has a company workspace open, a section for it sits
 * right under Companies: the company's name and its modules, so moving
 * between them doesn't mean scrolling back up to the tab bar.
 */
function workspaceSection(company, auth) {
    const base = `/companies/${company.id}`;
    const items = [{ to: base, end: true, label: 'Overview', icon: OVERVIEW_MODULE.icon, visible: () => true }];
    groupedModules(auth).forEach(({ group, modules }) => {
        items.push({ heading: group, visible: () => true });
        modules.forEach((m) => items.push({ to: `${base}/${m.path}`, label: m.label, icon: m.icon, visible: m.visible }));
    });
    return { id: 'workspace', label: company.name, isContext: true, visible: (a) => a.isSuperAdmin, items };
}

const COLLAPSE_KEY = 'tf.nav.collapsed';

function readCollapsed() {
    try { return JSON.parse(localStorage.getItem(COLLAPSE_KEY)) ?? {}; } catch { return {}; }
}

export default function AppLayout() {
    const auth = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const [open, setOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(readCollapsed);
    // Set by CompanyWorkspace (through the Outlet context) while one is open.
    const [workspaceCompany, setWorkspaceCompany] = useState(null);
    const outletContext = useMemo(() => ({ setWorkspaceCompany }), []);

    const toggleSection = (id) => setCollapsed((prev) => {
        const next = { ...prev, [id]: !prev[id] };
        try { localStorage.setItem(COLLAPSE_KEY, JSON.stringify(next)); } catch { /* per-viewer convenience only */ }
        return next;
    });

    const sections = useMemo(() => {
        const list = [...NAV];
        if (workspaceCompany) {
            list.splice(list.findIndex((sec) => sec.id === 'tenants') + 1, 0, workspaceSection(workspaceCompany, auth));
        }
        return list;
    }, [workspaceCompany, auth]);

    const doLogout = async () => {
        await auth.logout();
        navigate('/login', { replace: true });
    };

    const roleLabel = (auth.user?.access_role?.name || auth.user?.role || '').replace(/_/g, ' ');

    const hrefOf = (item) => (typeof item.to === 'function' ? item.to(auth) : item.to);
    const isActive = (item) => {
        if (item.heading) return false;
        const href = hrefOf(item);
        return item.end ? location.pathname === href : location.pathname === href || location.pathname.startsWith(`${href}/`);
    };

    const link = (item) => {
        if (item.heading) {
            return <div key={`h-${item.heading}`} className="tf-nav-subheading">{item.heading}</div>;
        }
        return item.visible(auth) ? (
            <NavLink
                key={hrefOf(item)}
                to={hrefOf(item)}
                end={item.end}
                className={({ isActive }) => `tf-nav-link${isActive ? ' active' : ''}`}
                onClick={() => setOpen(false)}
            >
                <i className={`bi ${item.icon}`} />
                <span>{item.label}</span>
            </NavLink>
        ) : null;
    };

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

                    <nav className="tf-nav-wrap" aria-label="Main">
                        {sections.map((section) => {
                            if (!section.visible(auth)) return null;
                            const items = section.items.filter((i) => i.visible(auth));
                            if (!items.some((i) => !i.heading)) return null;
                            if (!section.label) return <div key={section.id} className="tf-nav-group">{items.map(link)}</div>;

                            // A section holding the current page never hides it.
                            const hasActive = items.some(isActive);
                            const isOpen = hasActive || !collapsed[section.id];
                            return (
                                <div key={section.id} className={`tf-nav-group${section.isContext ? ' is-context' : ''}`}>
                                    <button
                                        type="button"
                                        className="tf-nav-section-label"
                                        onClick={() => toggleSection(section.id)}
                                        aria-expanded={isOpen}
                                        disabled={hasActive}
                                        title={hasActive ? undefined : (isOpen ? 'Collapse' : 'Expand')}
                                    >
                                        {section.isContext && <i className="bi bi-building me-1" />}
                                        <span className="text-truncate">{section.label}</span>
                                        {!hasActive && <i className={`bi ${isOpen ? 'bi-chevron-down' : 'bi-chevron-right'} tf-nav-chevron`} />}
                                    </button>
                                    {isOpen && items.map(link)}
                                </div>
                            );
                        })}
                    </nav>

                    <div className="tf-sidebar-footer">
                        <button className="tf-logout-btn" onClick={doLogout}>
                            <i className="bi bi-box-arrow-right" />Sign out
                        </button>
                    </div>
                </aside>

                <main className="tf-content">
                    <Outlet context={outletContext} />
                </main>
            </div>
        </div>
    );
}
