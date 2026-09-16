import { useEffect, useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import DataTable from '../../components/DataTable.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import StatusBadge from '../../components/StatusBadge.jsx';
import { terminals } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

const MODES = ['Both', 'Terminal', 'Pickup'];
const BLANK = { name: '', default_route_origin: '', boarding_mode: 'Both', status: 'Active' };

export default function Terminals() {
    const { can } = useAuth();
    const { rows, meta, loading, page, setPage, reload } = useList(terminals.list);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(BLANK);
    const [saving, setSaving] = useState(false);
    const [routeStops, setRouteStops] = useState([]);

    useEffect(() => {
        terminals.routeStops().then(setRouteStops).catch(() => setRouteStops([]));
    }, []);

    const openNew = () => { setForm(BLANK); setEditing({}); };
    const openEdit = (t) => {
        setForm({
            name: t.name,
            default_route_origin: t.default_route_origin ?? '',
            boarding_mode: t.boarding_mode,
            status: t.status,
        });
        setEditing(t);
    };

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const payload = { ...form, default_route_origin: form.default_route_origin || null };
            if (editing.id) {
                await terminals.update(editing.id, payload);
                notifySuccess('Terminal updated.');
            } else {
                await terminals.create(payload);
                notifySuccess('Terminal added.');
            }
            setEditing(null);
            reload();
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const remove = async (t) => {
        if (!(await confirmAction({ title: `Delete ${t.name}?`, danger: true, confirmText: 'Delete' }))) return;
        try {
            await terminals.remove(t.id);
            notifySuccess('Terminal deleted.');
            reload();
        } catch (err) {
            notifyError(err);
        }
    };

    const columns = [
        { key: 'name', header: 'Terminal', className: 'fw-semibold' },
        { key: 'boarding_mode', header: 'Boarding', render: (t) => <span className="badge tf-badge-inactive">{t.boarding_mode}</span> },
        { key: 'default_route_origin', header: 'Default origin', render: (t) => t.default_route_origin || <span className="text-muted">—</span> },
        { key: 'status', header: 'Status', render: (t) => <StatusBadge value={t.status.toLowerCase()} /> },
        {
            key: 'actions',
            header: '',
            className: 'text-end text-nowrap',
            render: (t) => (
                <>
                    {can('terminals.edit') && (
                        <button className="btn btn-sm btn-outline-secondary me-1" onClick={() => openEdit(t)}>
                            <i className="bi bi-pencil" />
                        </button>
                    )}
                    {can('terminals.delete') && (
                        <button className="btn btn-sm btn-outline-danger" onClick={() => remove(t)}>
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
                title="Terminals"
                subtitle="Where trips start. Pickup-only terminals skip the terminal boarding phase."
                actions={can('terminals.create') && (
                    <button className="btn btn-accent" onClick={openNew}>
                        <i className="bi bi-plus-lg me-1" /> Add terminal
                    </button>
                )}
            />

            <DataTable columns={columns} rows={rows} loading={loading} empty="No terminals yet." />
            <Pagination meta={meta} page={page} onPage={setPage} />

            <Modal
                open={!!editing}
                title={editing?.id ? `Edit ${editing.name}` : 'Add terminal'}
                onClose={() => setEditing(null)}
                footer={
                    <>
                        <button className="btn btn-light" onClick={() => setEditing(null)}>Cancel</button>
                        <button className="btn btn-primary" form="terminal-form" disabled={saving}>
                            {saving && <span className="spinner-border spinner-border-sm me-2" />}Save
                        </button>
                    </>
                }
            >
                <form id="terminal-form" onSubmit={save} className="vstack gap-3">
                    <div>
                        <label className="form-label">Name *</label>
                        <input className="form-control" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value.toUpperCase() })} />
                    </div>
                    <div className="row g-2">
                        <div className="col-md-6">
                            <label className="form-label">Boarding mode</label>
                            <select className="form-select" value={form.boarding_mode} onChange={(e) => setForm({ ...form, boarding_mode: e.target.value })}>
                                {MODES.map((m) => <option key={m} value={m}>{m}</option>)}
                            </select>
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Status</label>
                            <select className="form-select" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label className="form-label">Default route origin</label>
                        <select
                            className="form-select"
                            value={form.default_route_origin}
                            onChange={(e) => setForm({ ...form, default_route_origin: e.target.value })}
                        >
                            <option value="">— None —</option>
                            {(form.default_route_origin && !routeStops.includes(form.default_route_origin)) && (
                                <option value={form.default_route_origin}>{form.default_route_origin} (not a current stop)</option>
                            )}
                            {routeStops.map((stop) => <option key={stop} value={stop}>{stop}</option>)}
                        </select>
                        <div className="form-text">Optional — the stop name the "To" picker reverse-maps to.</div>
                    </div>
                </form>
            </Modal>
        </>
    );
}
