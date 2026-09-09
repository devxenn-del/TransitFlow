import { useEffect, useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import DataTable from '../../components/DataTable.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import StatusBadge from '../../components/StatusBadge.jsx';
import { companyUsers, thermalPrinters } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

const BLANK = { device_id: '', mac_address: '', model: '', status: 'Active' };

export default function ThermalPrinters() {
    const { can } = useAuth();
    const { rows, meta, loading, page, setPage, reload } = useList(thermalPrinters.list);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(BLANK);
    const [saving, setSaving] = useState(false);

    const [assigning, setAssigning] = useState(null); // printer being assigned
    const [conductors, setConductors] = useState([]);
    const [assignTo, setAssignTo] = useState('');

    const openNew = () => { setForm(BLANK); setEditing({}); };
    const openEdit = (p) => {
        setForm({ device_id: p.device_id, mac_address: p.mac_address ?? '', model: p.model, status: p.status });
        setEditing(p);
    };

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            if (editing.id) {
                await thermalPrinters.update(editing.id, form);
                notifySuccess('Printer updated.');
            } else {
                await thermalPrinters.create(form);
                notifySuccess('Printer added.');
            }
            setEditing(null);
            reload();
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const remove = async (p) => {
        if (!(await confirmAction({ title: `Delete printer ${p.device_id}?`, danger: true, confirmText: 'Delete' }))) return;
        try {
            await thermalPrinters.remove(p.id);
            notifySuccess('Printer deleted.');
            reload();
        } catch (err) {
            notifyError(err);
        }
    };

    const openAssign = async (p) => {
        setAssigning(p);
        setAssignTo(p.holder?.id ?? '');
        const list = await companyUsers.list({ role: 'conductor', per_page: 200 });
        setConductors(list.data ?? []);
    };

    const saveAssign = async () => {
        try {
            await thermalPrinters.assign(assigning.id, assignTo || null);
            notifySuccess('Printer assignment saved.');
            setAssigning(null);
            reload();
        } catch (err) {
            notifyError(err);
        }
    };

    const columns = [
        { key: 'device_id', header: 'Device ID', className: 'fw-semibold', render: (p) => <code>{p.device_id}</code> },
        { key: 'model', header: 'Model' },
        { key: 'mac_address', header: 'MAC address', render: (p) => p.mac_address || <span className="text-muted">—</span> },
        { key: 'holder', header: 'Assigned to', render: (p) => p.holder?.name ?? <span className="text-muted">Unassigned</span> },
        { key: 'status', header: 'Status', render: (p) => <StatusBadge value={p.status.toLowerCase()} /> },
        {
            key: 'actions', header: '', className: 'text-end text-nowrap',
            render: (p) => (
                <>
                    {can('thermalprinters.edit') && (
                        <button className="btn btn-sm btn-outline-secondary me-1" title="Assign to conductor" onClick={() => openAssign(p)}>
                            <i className="bi bi-person-check" />
                        </button>
                    )}
                    {can('thermalprinters.edit') && (
                        <button className="btn btn-sm btn-outline-secondary me-1" onClick={() => openEdit(p)}>
                            <i className="bi bi-pencil" />
                        </button>
                    )}
                    {can('thermalprinters.delete') && (
                        <button className="btn btn-sm btn-outline-danger" onClick={() => remove(p)}>
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
                title="Thermal Printers"
                subtitle="Printer inventory — each printer can be assigned to one conductor at a time."
                actions={can('thermalprinters.create') && (
                    <button className="btn btn-accent" onClick={openNew}>
                        <i className="bi bi-plus-lg me-1" /> Add printer
                    </button>
                )}
            />

            <DataTable columns={columns} rows={rows} loading={loading} empty="No thermal printers yet." />
            <Pagination meta={meta} page={page} onPage={setPage} />

            <Modal
                open={!!editing}
                title={editing?.id ? `Edit ${editing.device_id}` : 'Add printer'}
                onClose={() => setEditing(null)}
                footer={
                    <>
                        <button className="btn btn-light" onClick={() => setEditing(null)}>Cancel</button>
                        <button className="btn btn-primary" form="printer-form" disabled={saving}>
                            {saving && <span className="spinner-border spinner-border-sm me-2" />}Save
                        </button>
                    </>
                }
            >
                <form id="printer-form" onSubmit={save} className="vstack gap-3">
                    <div>
                        <label className="form-label">Device ID *</label>
                        <input className="form-control" required value={form.device_id} onChange={(e) => setForm({ ...form, device_id: e.target.value })} />
                    </div>
                    <div>
                        <label className="form-label">Model *</label>
                        <input className="form-control" required value={form.model} onChange={(e) => setForm({ ...form, model: e.target.value })} />
                    </div>
                    <div>
                        <label className="form-label">MAC address</label>
                        <input className="form-control" value={form.mac_address} onChange={(e) => setForm({ ...form, mac_address: e.target.value })} />
                    </div>
                    <div>
                        <label className="form-label">Status</label>
                        <select className="form-select" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </form>
            </Modal>

            <Modal
                open={!!assigning}
                title={assigning ? `Assign ${assigning.device_id}` : ''}
                onClose={() => setAssigning(null)}
                footer={
                    <>
                        <button className="btn btn-light" onClick={() => setAssigning(null)}>Cancel</button>
                        <button className="btn btn-primary" onClick={saveAssign}>Save</button>
                    </>
                }
            >
                <label className="form-label">Conductor</label>
                <select className="form-select" value={assignTo} onChange={(e) => setAssignTo(e.target.value)}>
                    <option value="">Unassigned</option>
                    {conductors.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
            </Modal>
        </>
    );
}
