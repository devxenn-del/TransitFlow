import { useEffect, useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import DataTable from '../../components/DataTable.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import StatusBadge from '../../components/StatusBadge.jsx';
import { adminAssignments, buses as busesApi, companyUsers } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

const SHIFTS = ['Morning', 'Evening'];
const BLANK = { bus_id: '', user_id: '', shift: 'Morning', effective_from: '', effective_to: '' };
const dt = (v) => v || '—';

export default function AdminAssignments() {
    const { can } = useAuth();
    const { rows, meta, loading, page, setPage, reload } = useList(adminAssignments.list);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(BLANK);
    const [saving, setSaving] = useState(false);
    const [buses, setBuses] = useState([]);
    const [holders, setHolders] = useState([]);

    useEffect(() => {
        busesApi.list({ per_page: 200 }).then((r) => setBuses(r.data ?? []));
        companyUsers.list({ per_page: 200 }).then((r) => setHolders((r.data ?? []).filter((u) => u.access_role?.key !== 'conductor')));
    }, []);

    const openNew = () => {
        setForm({ ...BLANK, effective_from: new Date().toISOString().slice(0, 10) });
        setEditing({});
    };

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const payload = { ...form, effective_to: form.effective_to || null };
            await adminAssignments.create(payload);
            notifySuccess('Assignment created.');
            setEditing(null);
            reload();
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const endAssignment = async (a) => {
        if (!(await confirmAction({ title: `End this assignment for ${a.bus?.bus_number}?`, confirmText: 'End assignment' }))) return;
        try {
            await adminAssignments.update(a.id, { status: 'Inactive' });
            notifySuccess('Assignment ended.');
            reload();
        } catch (err) {
            notifyError(err);
        }
    };

    const remove = async (a) => {
        if (!(await confirmAction({ title: 'Delete this assignment?', danger: true, confirmText: 'Delete' }))) return;
        try {
            await adminAssignments.remove(a.id);
            notifySuccess('Assignment deleted.');
            reload();
        } catch (err) {
            notifyError(err);
        }
    };

    const columns = [
        { key: 'bus', header: 'Bus', className: 'fw-semibold', render: (a) => a.bus?.bus_number ?? '—' },
        { key: 'user', header: 'Assigned to', render: (a) => a.user?.name ?? '—' },
        { key: 'shift', header: 'Shift' },
        { key: 'from', header: 'From', render: (a) => dt(a.effective_from) },
        { key: 'to', header: 'To', render: (a) => dt(a.effective_to) },
        { key: 'status', header: 'Status', render: (a) => <StatusBadge value={a.status.toLowerCase()} /> },
        {
            key: 'actions', header: '', className: 'text-end text-nowrap',
            render: (a) => (
                <>
                    {can('adminassignments.edit') && a.status === 'Active' && (
                        <button className="btn btn-sm btn-outline-secondary me-1" onClick={() => endAssignment(a)}>
                            <i className="bi bi-stop-circle me-1" />End
                        </button>
                    )}
                    {can('adminassignments.delete') && (
                        <button className="btn btn-sm btn-outline-danger" onClick={() => remove(a)}>
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
                title="Admin Assignments"
                subtitle="Which office/management account is responsible for a bus, per shift, over a date range."
                actions={can('adminassignments.create') && (
                    <button className="btn btn-accent" onClick={openNew}>
                        <i className="bi bi-plus-lg me-1" /> New assignment
                    </button>
                )}
            />

            <DataTable columns={columns} rows={rows} loading={loading} empty="No admin bus assignments yet." />
            <Pagination meta={meta} page={page} onPage={setPage} />

            <Modal
                open={!!editing}
                title="New admin bus assignment"
                onClose={() => setEditing(null)}
                footer={
                    <>
                        <button className="btn btn-light" onClick={() => setEditing(null)}>Cancel</button>
                        <button className="btn btn-primary" form="aba-form" disabled={saving}>
                            {saving && <span className="spinner-border spinner-border-sm me-2" />}Save
                        </button>
                    </>
                }
            >
                <form id="aba-form" onSubmit={save} className="vstack gap-3">
                    <div className="row g-2">
                        <div className="col-md-6">
                            <label className="form-label">Bus *</label>
                            <select className="form-select" required value={form.bus_id} onChange={(e) => setForm({ ...form, bus_id: e.target.value })}>
                                <option value="">Select…</option>
                                {buses.map((b) => <option key={b.id} value={b.id}>{b.bus_number}</option>)}
                            </select>
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Assign to *</label>
                            <select className="form-select" required value={form.user_id} onChange={(e) => setForm({ ...form, user_id: e.target.value })}>
                                <option value="">Select…</option>
                                {holders.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                            </select>
                        </div>
                    </div>
                    <div>
                        <label className="form-label">Shift *</label>
                        <select className="form-select" value={form.shift} onChange={(e) => setForm({ ...form, shift: e.target.value })}>
                            {SHIFTS.map((s) => <option key={s} value={s}>{s}</option>)}
                        </select>
                    </div>
                    <div className="row g-2">
                        <div className="col-md-6">
                            <label className="form-label">Effective from *</label>
                            <input type="date" className="form-control" required value={form.effective_from} onChange={(e) => setForm({ ...form, effective_from: e.target.value })} />
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Effective to</label>
                            <input type="date" className="form-control" value={form.effective_to} onChange={(e) => setForm({ ...form, effective_to: e.target.value })} />
                            <div className="form-text">Leave blank for open-ended.</div>
                        </div>
                    </div>
                    <p className="small text-body-secondary mb-0">
                        A bus can only have one active holder per shift over an overlapping date range.
                    </p>
                </form>
            </Modal>
        </>
    );
}
