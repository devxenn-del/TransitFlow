import { createContext, useContext } from 'react';

import { useAuth } from '../auth/AuthContext.jsx';

/**
 * Set by CompanyWorkspace while a company is open (`/companies/:id/...`):
 * `{ company, basePath, reload }`. Null on the standalone `/company/*` pages.
 */
export const CompanyScopeContext = createContext(null);

export function useCompanyScope() {
    return useContext(CompanyScopeContext);
}

/**
 * The company the current page is operating on — the open workspace's
 * company, or else the signed-in user's own.
 */
export function useActiveCompany() {
    const scope = useCompanyScope();
    const { user } = useAuth();
    return scope?.company ?? user?.company ?? null;
}

/**
 * Builds a link to a company module that stays inside the open workspace:
 * `companyPath('users/5')` → `/companies/3/users/5` in a workspace,
 * `/company/users/5` on the standalone pages.
 */
export function useCompanyPath() {
    const scope = useCompanyScope();
    return (path) => (scope ? `${scope.basePath}/${path}` : `/company/${path}`);
}
