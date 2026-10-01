import { useEffect, useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import DataTable from '../../components/DataTable.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import StatusBadge from '../../components/StatusBadge.jsx';
import { routes } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

const BLANK = { origin: '', destination: '', name: '', status: 'Active' };
const peso = (n) => `₱${Number(n ?? 0).toFixed(2)}`;

/**
 * Origin → destination legs. Fares are not set here — they come from each
 * franchise's fare-matrix grid — so this page only shows whether a leg is
 * priced yet.
 */
export default function Routes() {
    const { can } = useAuth();
    const [search, setSearch] = useState('');
    const [q, setQ] = useState('');
    const { rows, meta, loading, page, setPage, reload } = useList(
        (params) => routes.list({ ...params, q: q || undefined }),
        { deps: [q] },
    );
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(BLANK);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const t = setTimeout(() => { setQ(search.trim()); setPage(1); }, 300);
        return () => clearTimeout(t);
    }, [search, setPage]);

    const openNew = () => { setForm(BLANK); setEditing({}); };
    const openEdit = (r) => {
        setForm({ origin: r.origin, destination: r.destination, name: r.name ?? '', status: r.status });
        setEditing(r);
    };

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const payload = { ...form, name: form.name || null };
            if (editing.id) {
                await routes.update(editing.id, payload);
                notifySuccess('Route updated.');
            } else {
                await routes.create(payload);
                notifySuccess('Route added.');
            }
            setEditing(null);
            reload();
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const remove = async (r) => {
        if (!(await confirmAction({ title: `Delete ${r.origin} → ${r.destination}?`, text: 'Its fare is removed too.', danger: true, confirmText: 'Delete' }))) return;
        try {
            await routes.remove(r.id);
            notifySuccess('Route deleted.');
            reload();
        } catch (err) {
            notifyError(err);
        }
    };

    const columns = [
        {
            key: 'leg',
            header: 'Route',
            render: (r) => (
                <>
                    <div className="fw-semibold">{r.origin} <i className="bi bi-arrow-right text-body-tertiary mx-1" /> {r.destination}</div>
                    {r.name && <div className="small text-body-secondary">{r.name}</div>}
                </>
            ),
        },
        {
            key: 'fare',
            header: 'Fare',
            render: (r) => (r.is_priced && r.fare
                ? <span className="fw-semibold">{peso(r.fare.amount)}{r.fare.discounted_amount != null && <span className="small text-body-secondary ms-2">disc. {peso(r.fare.discounted_amount)}</span>}</span>
                : <span className="badge text-bg-light border text-body-secondary">Not priced</span>),
        },
        { key: 'status', header: 'Status', render: (r) => <StatusBadge value={r.status.toLowerCase()} /> },
        {
            key: 'actions',
            header: '',
            className: 'text-end text-nowrap',
            render: (r) => (
                <>
                    {can('routes.edit') && (
                        <button className="btn btn-sm btn-outline-secondary me-1" onClick={() => openEdit(r)} aria-label="Edit route"><i className="bi bi-pencil" /></button>
                    )}
                    {can('routes.delete') && (
                        <button className="btn btn-sm btn-outline-danger" onClick={() => remove(r)} aria-label="Delete route"><i className="bi bi-trash" /></button>
                    )}
                </>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Fares & Routes"
                title="Routes"
                subtitle="Origin → destination legs. Fares are set on each franchise's fare matrix."
                actions={can('routes.create') && (
                    <button className="btn btn-primary" onClick={openNew}><i className="bi bi-plus-lg me-1" /> Add route</button>
                )}
            />

            <div className="input-group mb-3" style={{ maxWidth: 380 }}>
                <span className="input-group-text bg-body"><i className="bi bi-search" /></span>
                <input className="form-control" placeholder="Search origin, destination or name" value={search} onChange={(e) => setSearch(e.target.value)} />
            </div>

            <DataTable columns={columns} rows={rows} loading={loading} empty={q ? 'No routes match your search.' : 'No routes yet.'} />
            <Pagination meta={meta} page={page} onPage={setPage} />

            <Modal
                open={!!editing}
                title={editing?.id ? 'Edit route' : 'Add route'}
                onClose={() => setEditing(null)}
                footer={(
                    <>
                        <button className="btn btn-light" onClick={() => setEditing(null)}>Cancel</button>
                        <button className="btn btn-primary" form="route-form" disabled={saving}>
                            {saving && <span className="spinner-border spinner-border-sm me-2" />}Save
                        </button>
                    </>
                )}
            >
                <form id="route-form" onSubmit={save} className="vstack gap-3">
                    <div className="row g-2">
                        <div className="col-md-6">
                            <label className="form-label">Origin *</label>
                            <input className="form-control" required maxLength={150} value={form.origin} onChange={(e) => setForm({ ...form, origin: e.target.value })} />
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Destination *</label>
                            <input className="form-control" required maxLength={150} value={form.destination} onChange={(e) => setForm({ ...form, destination: e.target.value })} />
                        </div>
                    </div>
                    <div>
                        <label className="form-label">Name</label>
                        <input className="form-control" maxLength={150} placeholder="Generated from origin and destination if blank" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
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
        </>
    );
}
