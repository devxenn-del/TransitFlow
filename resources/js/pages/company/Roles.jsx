import { useEffect, useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import DataTable from '../../components/DataTable.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import { companyRoles } from '../../lib/api.js';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

const BLANK = { name: '', description: '', permission_keys: [], apply_to_users: false };

export default function Roles() {
    const { can } = useAuth();
    const manage = can('roles.manage');
    const [rows, setRows] = useState([]);
    const [catalogue, setCatalogue] = useState({});
    const [loading, setLoading] = useState(true);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(BLANK);
    const [saving, setSaving] = useState(false);

    const load = () => {
        setLoading(true);
        companyRoles.list()
            .then((r) => { setRows(r.data); setCatalogue(r.catalogue ?? {}); })
            .catch(notifyError)
            .finally(() => setLoading(false));
    };
    useEffect(load, []);

    const openNew = () => { setForm(BLANK); setEditing({}); };
    const openEdit = (role) => {
        setForm({
            name: role.name,
            description: role.description ?? '',
            permission_keys: [...(role.default_permissions ?? [])],
            apply_to_users: false,
        });
        setEditing(role);
    };

    const toggleKey = (key) => setForm((f) => ({
        ...f,
        permission_keys: f.permission_keys.includes(key)
            ? f.permission_keys.filter((k) => k !== key)
            : [...f.permission_keys, key],
    }));
    const toggleGroup = (keys) => setForm((f) => {
        const all = keys.every((k) => f.permission_keys.includes(k));
        return {
            ...f,
            permission_keys: all
                ? f.permission_keys.filter((k) => !keys.includes(k))
                : [...new Set([...f.permission_keys, ...keys])],
        };
    });

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            if (editing.id) {
                await companyRoles.update(editing.id, form);
                notifySuccess('Role updated.');
            } else {
                await companyRoles.create(form);
                notifySuccess('Role created.');
            }
            setEditing(null);
            load();
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const remove = async (role) => {
        if (!(await confirmAction({ title: `Delete "${role.name}"?`, danger: true, confirmText: 'Delete' }))) return;
        try {
            await companyRoles.remove(role.id);
            notifySuccess('Role deleted.');
            load();
        } catch (err) {
            notifyError(err);
        }
    };

    const columns = [
        {
            key: 'name', header: 'Role',
            render: (r) => (
                <div>
                    <div className="fw-semibold">
                        {r.name}
                        {r.is_admin && <span className="badge text-bg-dark ms-2">admin</span>}
                    </div>
                    {r.description && <div className="small text-muted">{r.description}</div>}
                    <code className="small text-muted">{r.key}</code>
                </div>
            ),
        },
        { key: 'perms', header: 'Default permissions', render: (r) => <span className="badge text-bg-light border">{r.default_permissions?.length ?? 0}</span> },
        { key: 'users', header: 'Users', className: 'text-end', render: (r) => r.user_count ?? 0 },
        {
            key: 'actions', header: '', className: 'text-end text-nowrap',
            render: (r) => manage && (
                <>
                    <button className="btn btn-sm btn-outline-secondary me-1" onClick={() => openEdit(r)}>
                        <i className="bi bi-pencil" />
                    </button>
                    {!r.is_admin && (r.user_count ?? 0) === 0 && (
                        <button className="btn btn-sm btn-outline-danger" onClick={() => remove(r)}>
                            <i className="bi bi-trash" />
                        </button>
                    )}
                </>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Company"
                title="Roles"
                subtitle="Your company's roles. A new user's permissions start from the role's defaults, then can be fine-tuned per user."
                actions={manage && (
                    <button className="btn btn-accent" onClick={openNew}>
                        <i className="bi bi-plus-lg me-1" /> New role
                    </button>
                )}
            />

            <DataTable columns={columns} rows={rows} loading={loading} empty="No roles yet." />

            <Modal
                open={!!editing}
                title={editing?.id ? `Edit ${editing.name}` : 'New role'}
                onClose={() => setEditing(null)}
                size="lg"
                footer={(
                    <>
                        <button className="btn btn-light" onClick={() => setEditing(null)}>Cancel</button>
                        <button className="btn btn-primary" form="role-form" disabled={saving}>
                            {saving && <span className="spinner-border spinner-border-sm me-2" />}Save
                        </button>
                    </>
                )}
            >
                <form id="role-form" onSubmit={save} className="vstack gap-3">
                    <div className="row g-2">
                        <div className="col-md-6">
                            <label className="form-label">Name *</label>
                            <input className="form-control" required value={form.name}
                                onChange={(e) => setForm({ ...form, name: e.target.value })} />
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Description</label>
                            <input className="form-control" value={form.description}
                                onChange={(e) => setForm({ ...form, description: e.target.value })} />
                        </div>
                    </div>

                    <div>
                        <label className="form-label mb-1">Default permissions</label>
                        <div className="border rounded p-2" style={{ maxHeight: 340, overflow: 'auto' }}>
                            {Object.entries(catalogue).map(([group, perms]) => {
                                const keys = perms.map((p) => p.key);
                                const all = keys.every((k) => form.permission_keys.includes(k));
                                return (
                                    <div key={group} className="mb-2">
                                        <label className="form-check fw-semibold small">
                                            <input type="checkbox" className="form-check-input" checked={all}
                                                onChange={() => toggleGroup(keys)} />
                                            <span className="form-check-label">{group}</span>
                                        </label>
                                        <div className="ms-3 row g-1">
                                            {perms.map((p) => (
                                                <div className="col-sm-6" key={p.key}>
                                                    <label className="form-check small">
                                                        <input type="checkbox" className="form-check-input"
                                                            checked={form.permission_keys.includes(p.key)}
                                                            onChange={() => toggleKey(p.key)} />
                                                        <span className="form-check-label">{p.name}</span>
                                                    </label>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    {editing?.id && (
                        <label className="form-check">
                            <input type="checkbox" className="form-check-input" checked={form.apply_to_users}
                                onChange={(e) => setForm({ ...form, apply_to_users: e.target.checked })} />
                            <span className="form-check-label small">
                                Also add the newly-checked permissions to users who already have this role
                            </span>
                        </label>
                    )}
                </form>
            </Modal>
        </>
    );
}
