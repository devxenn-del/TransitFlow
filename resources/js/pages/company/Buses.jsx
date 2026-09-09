import { useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import StatusBadge from '../../components/StatusBadge.jsx';
import { buses } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

const STATUSES = ['Active', 'Inactive', 'Maintenance'];
const VEHICLE_TYPES = ['diesel', 'gasoline', 'electric'];
const BLANK = { bus_number: '', plate_number: '', capacity: '', model: '', vehicle_type: 'diesel', status: 'Active' };

export default function Buses() {
    const { can } = useAuth();
    const { rows, meta, loading, page, setPage, reload } = useList(buses.list);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(BLANK);
    const [saving, setSaving] = useState(false);

    const openNew = () => { setForm(BLANK); setEditing({}); };
    const openEdit = (b) => {
        setForm({
            bus_number: b.bus_number, plate_number: b.plate_number,
            capacity: b.capacity ?? '', model: b.model ?? '', vehicle_type: b.vehicle_type ?? 'diesel',
            status: b.status,
        });
        setEditing(b);
    };

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const payload = { ...form, capacity: form.capacity === '' ? null : form.capacity };
            if (editing.id) {
                await buses.update(editing.id, payload);
                notifySuccess('Bus updated.');
            } else {
                await buses.create(payload);
                notifySuccess('Bus added.');
            }
            setEditing(null);
            reload();
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const remove = async (b) => {
        if (!(await confirmAction({ title: `Delete bus ${b.bus_number}?`, danger: true, confirmText: 'Delete' }))) return;
        try {
            await buses.remove(b.id);
            notifySuccess('Bus deleted.');
            reload();
        } catch (err) {
            notifyError(err);
        }
    };

    return (
        <>
            <PageHeader
                title="Buses"
                subtitle="Your company's fleet"
                actions={can('buses.create') && (
                    <button className="btn btn-primary" onClick={openNew}>
                        <i className="bi bi-plus-lg me-1" /> Add bus
                    </button>
                )}
            />

            <div className="card border-0 shadow-sm">
                <div className="table-responsive">
                    <table className="table table-hover align-middle mb-0">
                        <thead className="table-light">
                            <tr>
                                <th>Bus #</th>
                                <th>Plate #</th>
                                <th>Model</th>
                                <th>Capacity</th>
                                <th>Vehicle type</th>
                                <th>Status</th>
                                <th className="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading && <tr><td colSpan={7} className="text-center py-4"><span className="spinner-border spinner-border-sm" /></td></tr>}
                            {!loading && rows.length === 0 && <tr><td colSpan={7} className="text-center py-4 text-body-secondary">No buses yet.</td></tr>}
                            {rows.map((b) => (
                                <tr key={b.id}>
                                    <td className="fw-semibold">{b.bus_number}</td>
                                    <td>{b.plate_number}</td>
                                    <td>{b.model || '—'}</td>
                                    <td>{b.capacity ?? '—'}</td>
                                    <td className="text-capitalize">{b.vehicle_type}</td>
                                    <td><StatusBadge value={b.status.toLowerCase()} /></td>
                                    <td className="text-end text-nowrap">
                                        {can('buses.edit') && (
                                            <button className="btn btn-sm btn-outline-secondary me-1" onClick={() => openEdit(b)}>
                                                <i className="bi bi-pencil" />
                                            </button>
                                        )}
                                        {can('buses.delete') && (
                                            <button className="btn btn-sm btn-outline-danger" onClick={() => remove(b)}>
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
                title={editing?.id ? `Edit ${editing.bus_number}` : 'Add bus'}
                onClose={() => setEditing(null)}
                footer={
                    <>
                        <button className="btn btn-light" onClick={() => setEditing(null)}>Cancel</button>
                        <button className="btn btn-primary" form="bus-form" disabled={saving}>
                            {saving && <span className="spinner-border spinner-border-sm me-2" />}Save
                        </button>
                    </>
                }
            >
                <form id="bus-form" onSubmit={save} className="vstack gap-3">
                    <div>
                        <label className="form-label">Bus number *</label>
                        <input className="form-control" required value={form.bus_number} onChange={(e) => setForm({ ...form, bus_number: e.target.value })} />
                    </div>
                    <div>
                        <label className="form-label">Plate number *</label>
                        <input className="form-control" required value={form.plate_number} onChange={(e) => setForm({ ...form, plate_number: e.target.value })} />
                    </div>
                    <div>
                        <label className="form-label">Model</label>
                        <input className="form-control" value={form.model} onChange={(e) => setForm({ ...form, model: e.target.value })} />
                    </div>
                    <div className="row g-2">
                        <div className="col-md-6">
                            <label className="form-label">Capacity</label>
                            <input type="number" min="1" className="form-control" value={form.capacity} onChange={(e) => setForm({ ...form, capacity: e.target.value })} />
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Vehicle type</label>
                            <select className="form-select text-capitalize" value={form.vehicle_type} onChange={(e) => setForm({ ...form, vehicle_type: e.target.value })}>
                                {VEHICLE_TYPES.map((t) => <option key={t} value={t} className="text-capitalize">{t}</option>)}
                            </select>
                        </div>
                    </div>
                    <div>
                        <label className="form-label">Status</label>
                        <select className="form-select" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                            {STATUSES.map((s) => <option key={s} value={s} className="text-capitalize">{s}</option>)}
                        </select>
                    </div>
                </form>
            </Modal>
        </>
    );
}
