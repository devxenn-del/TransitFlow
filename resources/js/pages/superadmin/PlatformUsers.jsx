import { useEffect, useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import StatusBadge from '../../components/StatusBadge.jsx';
import { companies as companiesApi, platformUsers, roles as rolesApi } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

const BLANK = { name: '', email: '', password: '', role_id: '', company_id: '' };

export default function PlatformUsers() {
    const { can, user: me } = useAuth();
    const { rows, meta, loading, page, setPage, reload } = useList(platformUsers.list);
    const [roles, setRoles] = useState([]);
    const [companies, setCompanies] = useState([]);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(BLANK);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        rolesApi.list().then(setRoles).catch(() => {});
        companiesApi.list({ per_page: 100 }).then((r) => setCompanies(r.data)).catch(() => {});
    }, []);

    const openNew = () => {
        setForm(BLANK);
        setEditing({});
    };
    const openEdit = (u) => {
        setForm({ name: u.name, email: u.email, password: '', role_id: u.access_role?.id ?? '', company_id: u.company_id ?? '' });
        setEditing(u);
    };

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const payload = { ...form, company_id: form.company_id || null };
            if (editing.id && !payload.password) delete payload.password;
            if (editing.id) {
                await platformUsers.update(editing.id, payload);
                notifySuccess('User updated.');
            } else {
                await platformUsers.create(payload);
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
            await platformUsers.remove(u.id);
            notifySuccess('User deleted.');
            reload();
        } catch (err) {
            notifyError(err);
        }
    };

    return (
        <>
            <PageHeader
                title="Platform Users"
                subtitle="Super Admins and Company Admins across every company"
                actions={can('platform.users.create') && (
                    <button className="btn btn-primary" onClick={openNew}>
                        <i className="bi bi-plus-lg me-1" /> New user
                    </button>
                )}
            />

            <div className="card border-0 shadow-sm">
                <div className="table-responsive">
                    <table className="table table-hover align-middle mb-0">
                        <thead className="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Company</th>
                                <th>Status</th>
                                <th className="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading && (
                                <tr><td colSpan={6} className="text-center py-4"><span className="spinner-border spinner-border-sm" /></td></tr>
                            )}
                            {!loading && rows.map((u) => (
                                <tr key={u.id}>
                                    <td className="fw-semibold">{u.name}</td>
                                    <td className="small">{u.email}</td>
                                    <td>
                                        <span className="badge text-bg-light border text-capitalize">
                                            {u.access_role?.name ?? u.role?.replaceAll('_', ' ')}
                                        </span>
                                    </td>
                                    <td className="small">{u.company?.name ?? <span className="text-body-secondary">Platform</span>}</td>
                                    <td><StatusBadge value={u.status} /></td>
                                    <td className="text-end text-nowrap">
                                        {can('platform.users.edit') && (
                                            <button className="btn btn-sm btn-outline-secondary me-1" onClick={() => openEdit(u)}>
                                                <i className="bi bi-pencil" />
                                            </button>
                                        )}
                                        {can('platform.users.delete') && u.id !== me.id && (
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
                        <button className="btn btn-primary" form="puser-form" disabled={saving}>
                            {saving && <span className="spinner-border spinner-border-sm me-2" />}Save
                        </button>
                    </>
                }
            >
                <form id="puser-form" onSubmit={save} className="vstack gap-3">
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
                    <div className="row g-2">
                        <div className="col-md-6">
                            <label className="form-label">Role *</label>
                            <select className="form-select" required value={form.role_id} onChange={(e) => setForm({ ...form, role_id: e.target.value })}>
                                <option value="">Select…</option>
                                {roles.map((r) => (
                                    <option key={r.id} value={r.id}>{r.name}{r.is_platform ? ' (platform)' : ''}</option>
                                ))}
                            </select>
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Company</label>
                            <select className="form-select" value={form.company_id} onChange={(e) => setForm({ ...form, company_id: e.target.value })}>
                                <option value="">Platform (no company)</option>
                                {companies.map((c) => (
                                    <option key={c.id} value={c.id}>{c.name}</option>
                                ))}
                            </select>
                        </div>
                    </div>
                </form>
            </Modal>
        </>
    );
}
