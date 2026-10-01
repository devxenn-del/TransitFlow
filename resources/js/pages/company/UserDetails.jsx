import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';

import { useAuth } from '../../auth/AuthContext.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import StatusBadge from '../../components/StatusBadge.jsx';
import { companyUsers } from '../../lib/api.js';
import { useCompanyPath } from '../../lib/companyScope.jsx';

const fmtDateTime = (v) => (v ? new Date(v).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—');

function Field({ label, children }) {
    return (
        <div className="col-sm-6 col-lg-4">
            <div className="small text-body-secondary">{label}</div>
            <div className="fw-medium">{children || <span className="text-body-tertiary">—</span>}</div>
        </div>
    );
}

/**
 * One account's profile. The API resolves the user inside the bound
 * company only, so another company's user id is a 404 here.
 */
export default function UserDetails() {
    const { userId } = useParams();
    const { can } = useAuth();
    const companyPath = useCompanyPath();
    const [user, setUser] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        let active = true;
        companyUsers.get(userId)
            .then((u) => active && setUser(u))
            .catch((err) => active && setError(err.response?.status ?? 500));
        return () => { active = false; };
    }, [userId]);

    if (error) {
        return (
            <div className="tf-empty">
                <i className="bi bi-person-x d-block fs-2 mb-2" />
                {error === 404 ? 'This user was not found in this company.' : 'Could not load this user.'}
                <div className="mt-3"><Link to={companyPath('users')}>Back to users</Link></div>
            </div>
        );
    }

    if (!user) {
        return <div className="d-flex justify-content-center py-5"><div className="spinner-border text-primary" role="status" /></div>;
    }

    const fullName = [user.first_name, user.middle_name, user.last_name].filter(Boolean).join(' ');

    return (
        <>
            <PageHeader
                title={user.name}
                subtitle={user.access_role?.name ?? user.role.replaceAll('_', ' ')}
                actions={(
                    <>
                        <Link to={companyPath('users')} className="btn btn-light"><i className="bi bi-arrow-left me-1" />All users</Link>
                        {can('permissions.view') && (
                            <Link to={companyPath(`users/${user.id}/permissions`)} className="btn btn-outline-secondary">
                                <i className="bi bi-shield-lock me-1" />Permissions
                            </Link>
                        )}
                    </>
                )}
            />

            <div className="card mb-3">
                <div className="card-body">
                    <div className="d-flex flex-wrap gap-2 mb-3">
                        <StatusBadge value={user.status} />
                        {user.is_locked && <span className="badge text-bg-danger"><i className="bi bi-lock-fill me-1" />Locked</span>}
                        {user.must_change_password && <span className="badge text-bg-warning">Must change password</span>}
                    </div>
                    <div className="row g-3">
                        <Field label="Full name">{fullName || user.name}</Field>
                        <Field label="Employee ID"><code>{user.employee_id}</code></Field>
                        <Field label="Role">{user.access_role?.name}</Field>
                        <Field label="Email">{user.email}</Field>
                        <Field label="Phone number">{user.phone}</Field>
                        <Field label="Gender">{user.sex}</Field>
                        <Field label="Address">{user.address}</Field>
                        <Field label="Last login">{user.last_login_at ? fmtDateTime(user.last_login_at) : 'Never'}</Field>
                        <Field label="Date created">{fmtDateTime(user.created_at)}</Field>
                        {user.access_role?.key === 'conductor' && (
                            <Field label="App PIN">{user.has_pin ? 'Set' : 'Not set'}</Field>
                        )}
                    </div>
                </div>
            </div>

            {user.access_role?.key === 'conductor' && (
                <div className="card">
                    <div className="card-header bg-transparent fw-semibold">Assigned buses</div>
                    <div className="card-body">
                        {user.buses?.length
                            ? user.buses.map((b) => <span key={b.id} className="badge text-bg-light border me-1">{b.bus_number} · {b.plate_number}</span>)
                            : <span className="text-body-secondary small">No buses assigned.</span>}
                    </div>
                </div>
            )}
        </>
    );
}
