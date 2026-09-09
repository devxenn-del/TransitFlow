import { useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import StatusBadge from '../../components/StatusBadge.jsx';
import { companies } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

const STATUSES = ['active', 'inactive', 'suspended'];
const BLANK = { name: '', code: '', email: '', phone: '', address_city: '', address_province: '', can_create_accounts: true };

export default function Companies() {
    const { can } = useAuth();
    const { rows, meta, loading, page, setPage, reload } = useList(companies.list);
    const [editing, setEditing] = useState(null); // null | {} (new) | company (edit)
    const [form, setForm] = useState(BLANK);
    const [withAdmin, setWithAdmin] = useState(true);
    const [admin, setAdmin] = useState({ name: '', email: '', password: '' });
    const [saving, setSaving] = useState(false);

    const openNew = () => {
        setForm(BLANK);
        setAdmin({ name: '', email: '', password: '' });
        setWithAdmin(true);
        setEditing({});
    };
    const openEdit = (c) => {
        setForm({
            name: c.name, code: c.code, email: c.email ?? '', phone: c.phone ?? '',
            address_city: c.address?.city ?? '', address_province: c.address?.province ?? '',
            can_create_accounts: c.can_create_accounts ?? true,
        });
        setEditing(c);
    };

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            if (editing.id) {
                await companies.update(editing.id, form);
                notifySuccess('Company updated.');
            } else {
                const payload = { ...form };
                if (!payload.code) delete payload.code;
                if (withAdmin && admin.email) payload.admin = admin;
                await companies.create(payload);
                notifySuccess('Company created.');
            }
            setEditing(null);
            reload();
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const changeStatus = async (c, status) => {
        try {
            await companies.setStatus(c.id, status);
            notifySuccess(`${c.name} is now ${status}.`);
            reload();
        } catch (err) {
            notifyError(err);
        }
    };

    const remove = async (c) => {
        if (!(await confirmAction({ title: `Delete ${c.name}?`, text: 'This soft-deletes the company.', danger: true, confirmText: 'Delete' }))) return;
        try {
            await companies.remove(c.id);
            notifySuccess('Company deleted.');
            reload();
        } catch (err) {
            notifyError(err);
        }
    };

    // --- Per-company permission availability ---
    const [permsFor, setPermsFor] = useState(null);
    const [permGroups, setPermGroups] = useState({});
    const [disabledSet, setDisabledSet] = useState(new Set());
    const [permSaving, setPermSaving] = useState(false);

    const openPerms = async (c) => {
        try {
            const data = await companies.permissions(c.id);
            setPermGroups(data.groups);
            setDisabledSet(new Set(data.disabled));
            setPermsFor(c);
        } catch (err) {
            notifyError(err);
        }
    };
    const togglePerm = (key) => setDisabledSet((prev) => {
        const next = new Set(prev);
        next.has(key) ? next.delete(key) : next.add(key);
        return next;
    });
    const toggleGroup = (keys, enable) => setDisabledSet((prev) => {
        const next = new Set(prev);
        keys.forEach((k) => (enable ? next.delete(k) : next.add(k)));
        return next;
    });
    const savePerms = async () => {
        setPermSaving(true);
        try {
            await companies.setPermissions(permsFor.id, [...disabledSet]);
            notifySuccess(`Feature access updated for ${permsFor.name}.`);
            setPermsFor(null);
        } catch (err) {
            notifyError(err);
        } finally {
            setPermSaving(false);
        }
    };

    return (
        <>
            <PageHeader
                title="Companies"
                subtitle="Every transport company on the platform"
                actions={can('companies.create') && (
                    <button className="btn btn-primary" onClick={openNew}>
                        <i className="bi bi-plus-lg me-1" /> New company
                    </button>
                )}
            />

            <div className="card border-0 shadow-sm">
                <div className="table-responsive">
                    <table className="table table-hover align-middle mb-0">
                        <thead className="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Code</th>
                                <th>Contact</th>
                                <th>Users</th>
                                <th>Status</th>
                                <th className="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading && (
                                <tr>
                                    <td colSpan={6} className="text-center py-4">
                                        <span className="spinner-border spinner-border-sm" />
                                    </td>
                                </tr>
                            )}
                            {!loading && rows.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-center py-4 text-body-secondary">No companies yet.</td>
                                </tr>
                            )}
                            {rows.map((c) => (
                                <tr key={c.id}>
                                    <td className="fw-semibold">{c.name}</td>
                                    <td><code>{c.code}</code></td>
                                    <td className="small">
                                        {c.email || <span className="text-body-secondary">—</span>}
                                        {c.phone && <div className="text-body-secondary">{c.phone}</div>}
                                    </td>
                                    <td>
                                        {c.users_count ?? '—'}
                                        {c.can_create_accounts === false && (
                                            <i className="bi bi-person-fill-lock text-warning ms-2" title="This company cannot create its own accounts" />
                                        )}
                                    </td>
                                    <td><StatusBadge value={c.status} /></td>
                                    <td className="text-end text-nowrap">
                                        {can('companies.edit') && (
                                            <button className="btn btn-sm btn-outline-secondary me-1" onClick={() => openEdit(c)}>
                                                <i className="bi bi-pencil" />
                                            </button>
                                        )}
                                        {can('companies.edit') && (
                                            <button className="btn btn-sm btn-outline-secondary me-1" title="Feature access" onClick={() => openPerms(c)}>
                                                <i className="bi bi-toggles" />
                                            </button>
                                        )}
                                        {can('companies.status') && (
                                            <div className="btn-group btn-group-sm me-1">
                                                <button className="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                                    Status
                                                </button>
                                                <ul className="dropdown-menu dropdown-menu-end">
                                                    {STATUSES.map((s) => (
                                                        <li key={s}>
                                                            <button className="dropdown-item text-capitalize" disabled={s === c.status} onClick={() => changeStatus(c, s)}>
                                                                {s}
                                                            </button>
                                                        </li>
                                                    ))}
                                                </ul>
                                            </div>
                                        )}
                                        {can('companies.delete') && (
                                            <button className="btn btn-sm btn-outline-danger" onClick={() => remove(c)}>
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
                title={editing?.id ? `Edit ${editing.name}` : 'New company'}
                onClose={() => setEditing(null)}
                footer={
                    <>
                        <button className="btn btn-light" onClick={() => setEditing(null)}>Cancel</button>
                        <button className="btn btn-primary" form="company-form" disabled={saving}>
                            {saving && <span className="spinner-border spinner-border-sm me-2" />}
                            Save
                        </button>
                    </>
                }
            >
                <form id="company-form" onSubmit={save} className="vstack gap-3">
                    <div>
                        <label className="form-label">Name *</label>
                        <input className="form-control" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                    </div>
                    {!editing?.id && (
                        <div>
                            <label className="form-label">Code</label>
                            <input className="form-control" placeholder="auto-generated if blank" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} />
                        </div>
                    )}
                    <div className="row g-2">
                        <div className="col-md-6">
                            <label className="form-label">Email</label>
                            <input type="email" className="form-control" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Phone</label>
                            <input className="form-control" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">City</label>
                            <input className="form-control" value={form.address_city} onChange={(e) => setForm({ ...form, address_city: e.target.value })} />
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Province</label>
                            <input className="form-control" value={form.address_province} onChange={(e) => setForm({ ...form, address_province: e.target.value })} />
                        </div>
                    </div>

                    <div className="form-check">
                        <input className="form-check-input" type="checkbox" id="cca"
                            checked={form.can_create_accounts}
                            onChange={(e) => setForm({ ...form, can_create_accounts: e.target.checked })} />
                        <label className="form-check-label" htmlFor="cca">
                            Allow this company to create its own user accounts
                            <span className="d-block small text-body-secondary">
                                When off, only you (the Super Admin) can add accounts for this company.
                            </span>
                        </label>
                    </div>

                    {!editing?.id && (
                        <div className="border-top pt-3">
                            <div className="form-check mb-2">
                                <input className="form-check-input" type="checkbox" id="wa" checked={withAdmin} onChange={(e) => setWithAdmin(e.target.checked)} />
                                <label className="form-check-label" htmlFor="wa">Create a Company Admin account</label>
                            </div>
                            {withAdmin && (
                                <div className="row g-2">
                                    <div className="col-md-6">
                                        <input className="form-control" placeholder="Admin name" value={admin.name} onChange={(e) => setAdmin({ ...admin, name: e.target.value })} />
                                    </div>
                                    <div className="col-md-6">
                                        <input type="email" className="form-control" placeholder="Admin email" value={admin.email} onChange={(e) => setAdmin({ ...admin, email: e.target.value })} />
                                    </div>
                                    <div className="col-12">
                                        <input type="text" className="form-control" placeholder="Temp password (min 8)" value={admin.password} onChange={(e) => setAdmin({ ...admin, password: e.target.value })} />
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                </form>
            </Modal>

            <Modal
                open={!!permsFor}
                size="lg"
                title={permsFor ? `Feature access — ${permsFor.name}` : ''}
                onClose={() => setPermsFor(null)}
                footer={(
                    <>
                        <button className="btn btn-light" onClick={() => setPermsFor(null)}>Cancel</button>
                        <button className="btn btn-primary" disabled={permSaving} onClick={savePerms}>
                            {permSaving && <span className="spinner-border spinner-border-sm me-2" />}Save
                        </button>
                    </>
                )}
            >
                <p className="small text-muted">
                    Uncheck a permission to make it unavailable to this company. Its Company Admin then can't
                    assign it, and any user who already has it loses access.
                </p>
                <div style={{ maxHeight: '55vh', overflowY: 'auto' }}>
                    {Object.entries(permGroups).map(([group, perms]) => {
                        const keys = perms.map((p) => p.key);
                        const allOn = keys.every((k) => !disabledSet.has(k));
                        return (
                            <div className="mb-3" key={group}>
                                <div className="d-flex justify-content-between align-items-center border-bottom pb-1 mb-1">
                                    <span className="fw-semibold small text-uppercase">{group}</span>
                                    <button className="btn btn-link btn-sm p-0" onClick={() => toggleGroup(keys, !allOn)}>
                                        {allOn ? 'Disable all' : 'Enable all'}
                                    </button>
                                </div>
                                {perms.map((p) => (
                                    <div className="form-check" key={p.key}>
                                        <input className="form-check-input" type="checkbox" id={`perm-${p.key}`}
                                            checked={!disabledSet.has(p.key)} onChange={() => togglePerm(p.key)} />
                                        <label className="form-check-label small" htmlFor={`perm-${p.key}`}>
                                            {p.name} <code className="text-body-tertiary">{p.key}</code>
                                        </label>
                                    </div>
                                ))}
                            </div>
                        );
                    })}
                </div>
            </Modal>
        </>
    );
}
