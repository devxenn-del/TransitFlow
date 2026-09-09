import { Navigate, useLocation } from 'react-router-dom';

import { useAuth } from '../auth/AuthContext.jsx';

/**
 * Gate for authenticated routes. Optionally requires a permission key (or
 * `superAdmin` / `companyAdmin`) — otherwise renders a 403 panel.
 */
export default function ProtectedRoute({ children, permission, superAdmin, companyAdmin }) {
    const { isAuthenticated, loading, can, isSuperAdmin, isCompanyAdmin } = useAuth();
    const location = useLocation();

    if (loading) {
        return (
            <div className="d-flex justify-content-center py-5">
                <div className="spinner-border text-primary" role="status" />
            </div>
        );
    }

    if (!isAuthenticated) {
        return <Navigate to="/login" replace state={{ from: location.pathname }} />;
    }

    const denied =
        (superAdmin && !isSuperAdmin) ||
        (companyAdmin && !isCompanyAdmin && !isSuperAdmin) ||
        (permission && !can(permission));

    if (denied) {
        return (
            <div className="text-center py-5">
                <i className="bi bi-shield-lock display-1 text-body-secondary" />
                <h1 className="h4 mt-3">Not authorized</h1>
                <p className="text-body-secondary">You don't have permission to view this page.</p>
            </div>
        );
    }

    return children;
}
