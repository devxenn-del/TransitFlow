import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';

import { useAuth } from '../../auth/AuthContext.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import StatusBadge from '../../components/StatusBadge.jsx';
import { companyUsers, roles as rolesApi } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

const BLANK = { name: '', email: '', password: '', role_id: '', status: 'active', pin: '' };

export default function CompanyUsers() {
    const { can, user: me } = useAuth();
    const navigate = useNavigate();
    const { rows, meta, loading, page, setPage, reload } = useList(companyUsers.list);
    const [roles, setRoles] = useState([]);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(BLANK);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        // Conductors get their own accounts screen (Fleet > Conductors).
        rolesApi.list().then((list) => setRoles(list.filter((r) => r.key !== 'conductor'))).catch(() => {});
    }, []);

    const openNew = () => { setForm(BLANK); setEditing({}); };
    const openEdit = (u) => {
        setForm({
            name: u.name, email: u.email, password: '', role_id: u.access_role?.id ?? '',
            status: u.status, pin: '',
        });
        setEditing(u);
    };

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const payload = { ...form };
            if (editing.id && !payload.password) delete payload.password;
            // The conductor App PIN is always optional here — left blank on
            // create, they set their own from the mobile app's Set PIN
            // screen; left blank on edit, whatever PIN they already have
            // (if any) is untouched.
            if (!payload.pin) delete payload.pin;
            if (editing.id) {
                await companyUsers.update(editing.id, payload);
                notifySuccess('User updated.');
            } else {
                await companyUsers.create(payload);
                notifySuccess('User created.');
            }
            setEditing(null);
            reload();
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const remove = async (u) => {
        if (!(await confirmAction({ title: `Delete ${u.name}?`, danger: true, confirmText: 'Delete' }))) return;
        try {
            await companyUsers.remove(u.id);
            notifySuccess('User deleted.');
            reload();
        } catch (err) {
            notifyError(err);
        }
    };

    const toggleLock = async (u) => {
        const locking = !u.is_locked;
        if (locking && !(await confirmAction({
            title: `Lock ${u.name}?`,
            text: 'They will be signed out and unable to sign back in until you unlock the account.',
            danger: true,
            confirmText: 'Lock',
        }))) return;
        try {
            await (locking ? companyUsers.lock(u.id) : companyUsers.unlock(u.id));
            notifySuccess(locking ? 'Account locked.' : 'Account unlocked.');
            reload();
        } catch (err) {
            notifyError(err);
        }
    };

    return (
        <>
            <PageHeader
                title="Users"
                subtitle="Accounts in your company"
                actions={can('accounts.create') && me.company?.can_create_accounts !== false && (
                    <button className="btn btn-primary" onClick={openNew}>
                        <i className="bi bi-plus-lg me-1" /> New user
                    </button>
                )}
            />

            {can('accounts.create') && me.company?.can_create_accounts === false && (
                <div className="alert alert-warning small">
                    <i className="bi bi-person-fill-lock me-1" />
                    Account creation is disabled for your company by the TransitFlow administrator. You can still edit or deactivate existing accounts.
                </div>
            )}

            <div className="card border-0 shadow-sm">
                <div className="table-responsive">
                    <table className="table table-hover align-middle mb-0">
                        <thead className="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th className="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading && <tr><td colSpan={5} className="text-center py-4"><span className="spinner-border spinner-border-sm" /></td></tr>}
                            {!loading && rows.map((u) => (
                                <tr key={u.id}>
                                    <td className="fw-semibold">
                                        {u.name}
                                        {u.id === me.id && <span className="badge text-bg-light border ms-2">you</span>}
                                    </td>
                                    <td className="small">{u.email}</td>
                                    <td>
                                        <span className="badge text-bg-light border text-capitalize">
                                            {u.access_role?.name ?? u.role?.replaceAll('_', ' ')}
                                        </span>
                                    </td>
                                    <td>
                                        <StatusBadge value={u.status} />
                                        {u.is_locked && (
                                            <span className="badge text-bg-danger ms-1" title={u.lock_type === 'admin' ? 'Locked by an administrator' : 'Locked until next shift'}>
                                                <i className="bi bi-lock-fill me-1" />Locked
                                            </span>
                                        )}
                                    </td>
                                    <td className="text-end text-nowrap">
                                        {can('accounts.lock') && u.id !== me.id && (
                                            <button
                                                className={`btn btn-sm me-1 ${u.is_locked ? 'btn-outline-success' : 'btn-outline-danger'}`}
                                                title={u.is_locked ? 'Unlock account' : 'Lock account'}
                                                onClick={() => toggleLock(u)}
                                            >
                                                <i className={`bi ${u.is_locked ? 'bi-unlock' : 'bi-lock'}`} />
                                            </button>
                                        )}
                                        {can('permissions.view') && (
                                            <button className="btn btn-sm btn-outline-secondary me-1" title="Permissions" onClick={() => navigate(`/company/users/${u.id}/permissions`)}>
                                                <i className="bi bi-shield-lock" />
                                            </button>
                                        )}
                                        {can('accounts.edit') && (
                                            <button className="btn btn-sm btn-outline-secondary me-1" onClick={() => openEdit(u)}>
                                                <i className="bi bi-pencil" />
                                            </button>
                                        )}
                                        {can('accounts.delete') && u.id !== me.id && (
                                            <button className="btn btn-sm btn-outline-danger" onClick={() => remove(u)}>
                                                <i className="bi bi-trash" />
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <Pagination meta={meta} page={page} onPage={setPage} />

            <Modal
                open={!!editing}
                title={editing?.id ? `Edit ${editing.name}` : 'New user'}
                onClose={() => setEditing(null)}
                footer={
                    <>
                        <button className="btn btn-light" onClick={() => setEditing(null)}>Cancel</button>
                        <button className="btn btn-primary" form="cuser-form" disabled={saving}>
                            {saving && <span className="spinner-border spinner-border-sm me-2" />}Save
                        </button>
                    </>
                }
            >
                <form id="cuser-form" onSubmit={save} className="vstack gap-3">
                    <div>
                        <label className="form-label">Name *</label>
                        <input className="form-control" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                    </div>
                    <div>
                        <label className="form-label">Email *</label>
                        <input type="email" className="form-control" required value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
                    </div>
                    <div>
                        <label className="form-label">{editing?.id ? 'New password (optional)' : 'Password *'}</label>
                        <input type="text" className="form-control" required={!editing?.id} minLength={8} value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
                    </div>
                    <div>
                        <label className="form-label">
                            App PIN {editing?.id && (
                                <span className={`badge ms-1 ${editing.has_pin ? 'text-bg-success' : 'text-bg-secondary'}`}>
                                    {editing.has_pin ? 'PIN set' : 'No PIN set'}
                                </span>
                            )}
                        </label>
                        <input
                            type="text"
                            inputMode="numeric"
                            pattern="\d{4}"
                            maxLength={4}
                            className="form-control"
                            placeholder="4 digits"
                            value={form.pin}
                            onChange={(e) => setForm({ ...form, pin: e.target.value.replace(/\D/g, '').slice(0, 4) })}
                        />
                        <div className="form-text">
                            {editing?.id
                                ? 'Leave blank to keep the current PIN. Used to sign in to the conductor mobile app by PIN instead of password.'
                                : "Optional — leave blank and the conductor can set their own from the mobile app's Set PIN screen."}
                        </div>
                    </div>
                    <div className="row g-2">
                        <div className="col-md-6">
                            <label className="form-label">Role *</label>
                            <select className="form-select" required value={form.role_id} onChange={(e) => setForm({ ...form, role_id: e.target.value })}>
                                <option value="">Select…</option>
                                {roles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                            </select>
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Status</label>
                            <select className="form-select" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <p className="small text-body-secondary mb-0">
                        The role's default permissions are applied on save. Fine-tune them from the
                        <i className="bi bi-shield-lock mx-1" />permissions screen.
                    </p>
                </form>
            </Modal>
        </>
    );
}
