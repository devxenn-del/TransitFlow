import { useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import DataTable from '../../components/DataTable.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import StatusBadge from '../../components/StatusBadge.jsx';
import { buses as busesApi, conductorBuses, conductors } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

const BLANK = { name: '', email: '', password: '', status: 'active', phone: '', address: '', sex: '', pin: '' };

export default function Conductors() {
    const { can, user: me } = useAuth();
    const { rows, meta, loading, page, setPage, reload } = useList(conductors.list);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(BLANK);
    const [saving, setSaving] = useState(false);

    // Assigned buses
    const [busUser, setBusUser] = useState(null);
    const [allBuses, setAllBuses] = useState([]);
    const [assigned, setAssigned] = useState([]);
    const [busSaving, setBusSaving] = useState(false);

    const openNew = () => { setForm(BLANK); setEditing({}); };
    const openEdit = (c) => {
        setForm({
            name: c.name, email: c.email, password: '', status: c.status,
            phone: c.phone ?? '', address: c.address ?? '', sex: c.sex ?? '', pin: '',
        });
        setEditing(c);
    };

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const payload = { ...form };
            if (editing.id && !payload.password) delete payload.password;
            // The App PIN is always optional — left blank, a conductor can set
            // their own from the mobile app's Set PIN screen; on edit, leaves
            // whatever PIN they already have untouched.
            if (!payload.pin) delete payload.pin;
            if (editing.id) {
                await conductors.update(editing.id, payload);
                notifySuccess('Conductor updated.');
            } else {
                await conductors.create(payload);
                notifySuccess('Conductor added.');
            }
            setEditing(null);
            reload();
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const remove = async (c) => {
        if (!(await confirmAction({ title: `Delete ${c.name}?`, danger: true, confirmText: 'Delete' }))) return;
        try {
            await conductors.remove(c.id);
            notifySuccess('Conductor deleted.');
            reload();
        } catch (err) {
            notifyError(err);
        }
    };

    const openBuses = async (c) => {
        setBusUser(c);
        const [all, mine] = await Promise.all([
            busesApi.list({ per_page: 200 }).then((r) => r.data),
            conductorBuses.get(c.id),
        ]);
        setAllBuses(all);
        setAssigned(mine.map((b) => b.id));
    };
    const toggleBus = (id) => setAssigned((a) => (a.includes(id) ? a.filter((x) => x !== id) : [...a, id]));
    const saveBuses = async () => {
        setBusSaving(true);
        try {
            await conductorBuses.sync(busUser.id, assigned);
            notifySuccess('Buses assigned.');
            setBusUser(null);
        } catch (err) {
            notifyError(err);
        } finally {
            setBusSaving(false);
        }
    };

    const columns = [
        { key: 'name', header: 'Conductor', className: 'fw-semibold' },
        { key: 'employee_id', header: 'Employee ID', render: (c) => <code>{c.employee_id ?? '—'}</code> },
        { key: 'email', header: 'Email', className: 'small' },
        { key: 'phone', header: 'Contact', render: (c) => c.phone || <span className="text-muted">—</span> },
        { key: 'status', header: 'Status', render: (c) => <StatusBadge value={c.status} /> },
        {
            key: 'actions',
            header: '',
            className: 'text-end text-nowrap',
            render: (c) => (
                <>
                    {can('conductors.edit') && (
                        <button className="btn btn-sm btn-outline-secondary me-1" title="Assigned buses" onClick={() => openBuses(c)}>
                            <i className="bi bi-bus-front" />
                        </button>
                    )}
                    {can('conductors.edit') && (
                        <button className="btn btn-sm btn-outline-secondary me-1" onClick={() => openEdit(c)}>
                            <i className="bi bi-pencil" />
                        </button>
                    )}
                    {can('conductors.delete') && (
                        <button className="btn btn-sm btn-outline-danger" onClick={() => remove(c)}>
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
                eyebrow="Fleet"
                title="Conductors"
                subtitle="Login accounts that run trips and issue tickets."
                actions={can('conductors.create') && me.company?.can_create_accounts !== false && (
                    <button className="btn btn-accent" onClick={openNew}>
                        <i className="bi bi-plus-lg me-1" /> Add conductor
                    </button>
                )}
            />

            {can('conductors.create') && me.company?.can_create_accounts === false && (
                <div className="alert alert-warning small">
                    <i className="bi bi-person-fill-lock me-1" />
                    Account creation is disabled for your company by the TransitFlow administrator. You can still edit or deactivate existing conductors.
                </div>
            )}

            <DataTable columns={columns} rows={rows} loading={loading} empty="No conductors yet." />
            <Pagination meta={meta} page={page} onPage={setPage} />

            <Modal
                open={!!editing}
                title={editing?.id ? `Edit ${editing.name}` : 'Add conductor'}
                onClose={() => setEditing(null)}
                footer={
                    <>
                        <button className="btn btn-light" onClick={() => setEditing(null)}>Cancel</button>
                        <button className="btn btn-primary" form="conductor-form" disabled={saving}>
                            {saving && <span className="spinner-border spinner-border-sm me-2" />}Save
                        </button>
                    </>
                }
            >
                <form id="conductor-form" onSubmit={save} className="vstack gap-3">
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
                                ? 'Leave blank to keep the current PIN. Used to sign in to the mobile app by PIN instead of password.'
                                : "Optional — leave blank and the conductor can set their own from the mobile app's Set PIN screen."}
                        </div>
                    </div>
                    <div className="row g-2">
                        <div className="col-md-6">
                            <label className="form-label">Phone Number</label>
                            <input className="form-control" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Gender</label>
                            <select className="form-select" value={form.sex} onChange={(e) => setForm({ ...form, sex: e.target.value })}>
                                <option value="">—</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label className="form-label">Address</label>
                        <input className="form-control" value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} />
                    </div>
                    <div>
                        <label className="form-label">Status</label>
                        <select className="form-select" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    {!editing?.id && <p className="small text-muted mb-0">An employee ID is generated automatically. The role's default permissions are applied on save.</p>}
                </form>
            </Modal>

            <Modal
                open={!!busUser}
                title={busUser ? `Assigned buses — ${busUser.name}` : ''}
                onClose={() => setBusUser(null)}
                footer={
                    <>
                        <button className="btn btn-light" onClick={() => setBusUser(null)}>Cancel</button>
                        <button className="btn btn-primary" onClick={saveBuses} disabled={busSaving}>
                            {busSaving && <span className="spinner-border spinner-border-sm me-2" />}Save
                        </button>
                    </>
                }
            >
                <p className="small text-muted">The conductor can run trips on any bus ticked here.</p>
                <div className="vstack gap-1">
                    {allBuses.map((b) => (
                        <label key={b.id} className="form-check">
                            <input
                                type="checkbox"
                                className="form-check-input"
                                checked={assigned.includes(b.id)}
                                onChange={() => toggleBus(b.id)}
                            />
                            <span className="form-check-label">{b.bus_number} · {b.plate_number}</span>
                        </label>
                    ))}
                    {allBuses.length === 0 && <span className="small text-muted">No buses yet — add some on the Buses page.</span>}
                </div>
            </Modal>
        </>
    );
}
