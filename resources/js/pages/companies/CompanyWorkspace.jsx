import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, NavLink, Outlet, useLocation, useOutletContext, useParams } from 'react-router-dom';

import { useAuth } from '../../auth/AuthContext.jsx';
import StatusBadge from '../../components/StatusBadge.jsx';
import { companies, companyWorkspace } from '../../lib/api.js';
import { setCompanyScope } from '../../lib/axios.js';
import { CompanyScopeContext } from '../../lib/companyScope.jsx';
import { COMPANY_MODULES, OVERVIEW_MODULE, groupedModules, moduleForPath } from '../../lib/companyModules.js';
import { notifyError, notifySuccess } from '../../lib/ui.js';

const STATUSES = ['active', 'inactive', 'suspended'];

const fmtDate = (v) => (v ? new Date(v).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' }) : '—');

function addressLine(address) {
    if (!address) return null;
    return [address.line, address.barangay, address.city, address.province].filter(Boolean).join(', ') || null;
}

function Blocked({ status }) {
    const notFound = status === 404;
    return (
        <div className="text-center py-5">
            <i className={`bi ${notFound ? 'bi-building-x' : 'bi-shield-lock'} display-1 text-body-secondary`} />
            <h1 className="h4 mt-3">{notFound ? 'Company not found' : 'Not authorized'}</h1>
            <p className="text-body-secondary">
                {notFound ? 'It may have been deleted.' : "You don't have access to this company."}
            </p>
        </div>
    );
}

/**
 * `/companies/:companyId/*` — the selected company as the container for all
 * of its modules. Everything rendered in the <Outlet /> talks to the API
 * with `X-Company-Id` set (see setCompanyScope), so the existing company
 * pages act on THIS company; the server decides whether the caller may.
 */
function CompanyWorkspace() {
    const { companyId } = useParams();
    const auth = useAuth();
    const { can, isSuperAdmin } = auth;
    const location = useLocation();
    const [payload, setPayload] = useState(null);
    const [errorStatus, setErrorStatus] = useState(null);
    const [reloadKey, setReloadKey] = useState(0);

    const reload = useCallback(() => setReloadKey((k) => k + 1), []);
    const { setWorkspaceCompany } = useOutletContext() ?? {};

    // Scope every API call to this company while the workspace is open.
    useEffect(() => {
        setCompanyScope(companyId);
        return () => setCompanyScope(null);
    }, [companyId]);

    useEffect(() => {
        let active = true;
        setErrorStatus(null);
        companyWorkspace
            .get(companyId)
            .then((res) => active && setPayload({ ...res, loadedAt: Date.now() }))
            .catch((err) => {
                if (!active) return;
                setPayload(null);
                setErrorStatus(err.response?.status ?? 500);
            });
        return () => { active = false; };
    }, [companyId, reloadKey]);

    const company = payload?.data;
    const basePath = `/companies/${companyId}`;

    // Let the sidebar show this company's modules while it's open.
    useEffect(() => {
        if (!company || !setWorkspaceCompany) return undefined;
        setWorkspaceCompany({ id: company.id, name: company.name });
        return () => setWorkspaceCompany(null);
    }, [company?.id, company?.name, setWorkspaceCompany]); // eslint-disable-line react-hooks/exhaustive-deps

    const scope = useMemo(() => (company ? {
        company: {
            ...company,
            logo_url: company.settings?.logo_url ?? null,
            color_accent: company.settings?.color_accent ?? null,
            color_accent_dark: company.settings?.color_accent_dark ?? null,
        },
        basePath,
        overview: payload,
        reload,
    } : null), [company, basePath, payload, reload]);

    // Primary modules are tabs; everything else the caller may open sits in "More", grouped.
    const tabs = [OVERVIEW_MODULE, ...COMPANY_MODULES.filter((m) => m.primary && m.visible(auth))];
    const moreGroups = groupedModules(auth)
        .map((g) => ({ ...g, modules: g.modules.filter((m) => !m.primary) }))
        .filter((g) => g.modules.length > 0);
    const currentModule = moduleForPath(location.pathname.slice(basePath.length));
    const currentInMore = currentModule && !currentModule.primary;

    const changeStatus = async (status) => {
        try {
            await companies.setStatus(company.id, status);
            notifySuccess(`${company.name} is now ${status}.`);
            reload();
        } catch (err) {
            notifyError(err);
        }
    };

    if (errorStatus) return <Blocked status={errorStatus} />;

    if (!company) {
        return (
            <div className="d-flex justify-content-center py-5">
                <div className="spinner-border text-primary" role="status" />
            </div>
        );
    }

    const address = addressLine(company.address);

    return (
        <CompanyScopeContext.Provider value={scope}>
            <nav aria-label="breadcrumb">
                <ol className="breadcrumb tf-breadcrumb">
                    {isSuperAdmin && <li className="breadcrumb-item"><Link to="/companies">Companies</Link></li>}
                    {currentModule && currentModule.path !== '' ? (
                        <>
                            <li className="breadcrumb-item"><Link to={basePath}>{company.name}</Link></li>
                            {currentModule.group && <li className="breadcrumb-item text-body-secondary">{currentModule.group}</li>}
                            <li className="breadcrumb-item active" aria-current="page">{currentModule.label}</li>
                        </>
                    ) : (
                        <li className="breadcrumb-item active" aria-current="page">{company.name}</li>
                    )}
                </ol>
            </nav>

            <header className="tf-workspace-head">
                <div className="tf-workspace-logo">
                    {scope.company.logo_url ? <img src={scope.company.logo_url} alt="" /> : <i className="bi bi-buildings" />}
                </div>
                <div className="flex-grow-1 min-w-0">
                    <div className="d-flex flex-wrap align-items-center gap-2">
                        <h1>{company.name}</h1>
                        <StatusBadge value={company.status} />
                    </div>
                    <div className="tf-workspace-meta">
                        <span><i className="bi bi-hash" /><code>{company.code}</code></span>
                        {company.email && <span><i className="bi bi-envelope" />{company.email}</span>}
                        {company.phone && <span><i className="bi bi-telephone" />{company.phone}</span>}
                        {address && <span><i className="bi bi-geo-alt" />{address}</span>}
                        <span><i className="bi bi-calendar3" />Registered {fmtDate(company.created_at)}</span>
                    </div>
                </div>
                {isSuperAdmin && can('companies.status') && (
                    <div className="dropdown flex-none">
                        <button className="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                            Status
                        </button>
                        <ul className="dropdown-menu dropdown-menu-end">
                            {STATUSES.map((st) => (
                                <li key={st}>
                                    <button className="dropdown-item text-capitalize" disabled={st === company.status} onClick={() => changeStatus(st)}>
                                        {st === company.status && <i className="bi bi-check2 me-2" />}{st}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </header>

            <div className="tf-workspace-tabbar">
                <nav className="tf-workspace-tabs" aria-label={`${company.name} sections`}>
                    {tabs.map((t) => (
                        <NavLink key={t.path || 'overview'} to={t.path ? `${basePath}/${t.path}` : basePath} end={t.path === ''}>
                            <i className={`bi ${t.icon}`} />{t.label}
                        </NavLink>
                    ))}
                </nav>
                {moreGroups.length > 0 && (
                    <div className="dropdown tf-workspace-more">
                        <button type="button" className={`tf-workspace-more-btn${currentInMore ? ' active' : ''}`} data-bs-toggle="dropdown" aria-expanded="false">
                            {currentInMore
                                ? <><i className={`bi ${currentModule.icon}`} />{currentModule.label}</>
                                : <><i className="bi bi-grid-3x3-gap" />More</>}
                            <i className="bi bi-chevron-down small" />
                        </button>
                        <div className="dropdown-menu dropdown-menu-end shadow tf-more-menu">
                            {moreGroups.map((g) => (
                                <div key={g.group} className="tf-more-group">
                                    <div className="tf-more-group-label">{g.group}</div>
                                    {g.modules.map((m) => (
                                        <NavLink key={m.path} to={`${basePath}/${m.path}`} className="dropdown-item">
                                            <i className={`bi ${m.icon} me-2`} />{m.label}
                                        </NavLink>
                                    ))}
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            <Outlet />
        </CompanyScopeContext.Provider>
    );
}

/** Keyed by company, so switching companies starts a fresh workspace instead of showing the last one's data. */
export default function CompanyWorkspaceRoute() {
    const { companyId } = useParams();
    return <CompanyWorkspace key={companyId} />;
}
