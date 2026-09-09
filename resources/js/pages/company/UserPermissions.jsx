import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';

import { useAuth } from '../../auth/AuthContext.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import { companyUsers, permissionCatalogue } from '../../lib/api.js';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

export default function UserPermissions() {
    const { id } = useParams();
    const navigate = useNavigate();
    const { can } = useAuth();
    const canManage = can('permissions.manage');

    const [groups, setGroups] = useState([]);
    const [state, setState] = useState(null); // { user, granted:Set, roleDefaults:Set }
    const [saving, setSaving] = useState(false);

    const load = async () => {
        const [cat, detail] = await Promise.all([permissionCatalogue.list(), companyUsers.permissions(id)]);
        setGroups(cat);
        setState({
            user: detail.user.data ?? detail.user,
            granted: new Set(detail.granted),
            roleDefaults: new Set(detail.role_defaults),
        });
    };

    useEffect(() => {
        load().catch((err) => notifyError(err, 'Could not load permissions.'));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [id]);

    const toggle = (key) => {
        setState((s) => {
            const granted = new Set(s.granted);
            granted.has(key) ? granted.delete(key) : granted.add(key);
            return { ...s, granted };
        });
    };

    const dirty = useMemo(() => {
        if (!state) return false;
        return true; // simple: always allow save
    }, [state]);

    const save = async () => {
        setSaving(true);
        try {
            const res = await companyUsers.syncPermissions(id, [...state.granted]);
            setState((s) => ({ ...s, granted: new Set(res.granted) }));
            notifySuccess('Permissions saved.');
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const reset = async () => {
        if (!(await confirmAction({ title: 'Reset to role defaults?', text: 'Custom grants for this user will be discarded.', confirmText: 'Reset' }))) return;
        try {
            const res = await companyUsers.resetPermissions(id);
            setState((s) => ({ ...s, granted: new Set(res.granted) }));
            notifySuccess('Permissions reset to role defaults.');
        } catch (err) {
            notifyError(err);
        }
    };

    if (!state) {
        return <div className="d-flex justify-content-center py-5"><span className="spinner-border text-primary" /></div>;
    }

    return (
        <>
            <PageHeader
                title={`Permissions — ${state.user.name}`}
                subtitle={<>{state.user.email} · role <span className="text-capitalize">{state.user.access_role?.name ?? state.user.role?.replaceAll('_', ' ')}</span></>}
                actions={
                    <>
                        <button className="btn btn-outline-secondary" onClick={() => navigate('/company/users')}>
                            <i className="bi bi-arrow-left me-1" /> Back
                        </button>
                        {canManage && (
                            <>
                                <button className="btn btn-outline-secondary" onClick={reset}>Reset to role</button>
                                <button className="btn btn-primary" disabled={saving || !dirty} onClick={save}>
                                    {saving && <span className="spinner-border spinner-border-sm me-2" />}Save
                                </button>
                            </>
                        )}
                    </>
                }
            />

            <div className="row g-3">
                {groups.map((g) => (
                    <div className="col-md-6 col-xl-4" key={g.id}>
                        <div className="card border-0 shadow-sm h-100">
                            <div className="card-header bg-white fw-semibold">{g.name}</div>
                            <ul className="list-group list-group-flush">
                                {g.permissions.map((p) => {
                                    const checked = state.granted.has(p.key);
                                    const isDefault = state.roleDefaults.has(p.key);
                                    return (
                                        <li className="list-group-item d-flex align-items-start gap-2" key={p.id}>
                                            <input
                                                type="checkbox"
                                                className="form-check-input mt-1"
                                                checked={checked}
                                                disabled={!canManage}
                                                onChange={() => toggle(p.key)}
                                            />
                                            <div className="flex-grow-1">
                                                <div className="d-flex align-items-center gap-2">
                                                    <span>{p.name}</span>
                                                    {isDefault && <span className="badge text-bg-light border">role default</span>}
                                                </div>
                                                <code className="small text-body-secondary">{p.key}</code>
                                                {p.description && <div className="small text-body-secondary">{p.description}</div>}
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    </div>
                ))}
            </div>
        </>
    );
}
