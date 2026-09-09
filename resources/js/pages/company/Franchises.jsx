import { useState } from 'react';
import { useNavigate } from 'react-router-dom';

import { useAuth } from '../../auth/AuthContext.jsx';
import DataTable from '../../components/DataTable.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import StatusBadge from '../../components/StatusBadge.jsx';
import { franchises } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

const BLANK = {
    applicant_name: '',
    route_description: '',
    route_origin: '',
    route_destination: '',
    case_no: '',
    status: 'Active',
};

export default function Franchises() {
    const { can } = useAuth();
    const navigate = useNavigate();
    const { rows, meta, loading, page, setPage, reload } = useList(franchises.list);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(BLANK);
    const [saving, setSaving] = useState(false);

    const openNew = () => { setForm(BLANK); setEditing({}); };
    const openEdit = (f) => {
        setForm({
            applicant_name: f.applicant_name,
            route_description: f.route_description ?? '',
            route_origin: f.route_origin ?? '',
            route_destination: f.route_destination ?? '',
            case_no: f.case_no ?? '',
            status: f.status,
        });
        setEditing(f);
    };

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            if (editing.id) {
                await franchises.update(editing.id, form);
                notifySuccess('Franchise updated.');
            } else {
                await franchises.create(form);
                notifySuccess('Franchise added.');
            }
            setEditing(null);
            reload();
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const remove = async (f) => {
        if (!(await confirmAction({
            title: `Delete ${f.applicant_name}?`,
            text: 'Its route stops, routes and fares are deleted too.',
            danger: true,
            confirmText: 'Delete',
        }))) return;
        try {
            await franchises.remove(f.id);
            notifySuccess('Franchise deleted.');
            reload();
        } catch (err) {
            notifyError(err);
        }
    };

    const columns = [
        {
            key: 'name',
            header: 'Franchise',
            render: (f) => (
                <div>
                    <div className="fw-semibold">{f.applicant_name}</div>
                    {f.route_description && <div className="small text-muted">{f.route_description}</div>}
                </div>
            ),
        },
        { key: 'case_no', header: 'Case no.', render: (f) => <code>{f.case_no || '—'}</code> },
        {
            key: 'grid',
            header: 'Grid',
            render: (f) => (
                <span className="small">
                    <span className="fw-semibold">{f.stops_count ?? 0}</span> stops ·{' '}
                    <span className="fw-semibold">{f.priced_routes_count ?? 0}</span>/{f.routes_count ?? 0} fares
                </span>
            ),
        },
        { key: 'status', header: 'Status', render: (f) => <StatusBadge value={f.status.toLowerCase()} /> },
        {
            key: 'actions',
            header: '',
            className: 'text-end text-nowrap',
            render: (f) => (
                <>
                    <button
                        className="btn btn-sm btn-primary me-1"
                        onClick={() => navigate(`/company/fare-matrix/${f.id}`)}
                        title="Open the fare-matrix grid"
                    >
                        <i className="bi bi-grid-3x3-gap-fill me-1" /> Fare Matrix
                    </button>
                    {can('franchises.edit') && (
                        <button className="btn btn-sm btn-outline-secondary me-1" onClick={() => openEdit(f)}>
                            <i className="bi bi-pencil" />
                        </button>
                    )}
                    {can('franchises.delete') && (
                        <button className="btn btn-sm btn-outline-danger" onClick={() => remove(f)}>
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
                title="Fare Matrix"
                subtitle="Each franchise has its own stop list and fare grid. Open one to edit fares cell-by-cell."
                actions={can('franchises.create') && (
                    <button className="btn btn-accent" onClick={openNew}>
                        <i className="bi bi-plus-lg me-1" /> Add franchise
                    </button>
                )}
            />

            <DataTable columns={columns} rows={rows} loading={loading} empty="No franchises yet. Add one to start a fare matrix." />
            <Pagination meta={meta} page={page} onPage={setPage} />

            <Modal
                open={!!editing}
                title={editing?.id ? 'Edit franchise' : 'Add franchise'}
                onClose={() => setEditing(null)}
                size="lg"
                footer={
                    <>
                        <button className="btn btn-light" onClick={() => setEditing(null)}>Cancel</button>
                        <button className="btn btn-primary" form="franchise-form" disabled={saving}>
                            {saving && <span className="spinner-border spinner-border-sm me-2" />}Save
                        </button>
                    </>
                }
            >
                <form id="franchise-form" onSubmit={save} className="vstack gap-3">
                    <div>
                        <label className="form-label">Name of applicant *</label>
                        <input className="form-control" required value={form.applicant_name} onChange={(e) => setForm({ ...form, applicant_name: e.target.value })} />
                    </div>
                    <div>
                        <label className="form-label">Route description (as granted)</label>
                        <input className="form-control" value={form.route_description} onChange={(e) => setForm({ ...form, route_description: e.target.value })} placeholder="e.g. SM PALA-PALA - EPZA (ROSARIO) VIA GEN. TRIAS AND VICE VERSA" />
                    </div>
                    <div className="row g-2">
                        <div className="col-md-5">
                            <label className="form-label">Route origin</label>
                            <input className="form-control" value={form.route_origin} onChange={(e) => setForm({ ...form, route_origin: e.target.value.toUpperCase() })} />
                        </div>
                        <div className="col-md-5">
                            <label className="form-label">Route destination</label>
                            <input className="form-control" value={form.route_destination} onChange={(e) => setForm({ ...form, route_destination: e.target.value.toUpperCase() })} />
                        </div>
                        <div className="col-md-2">
                            <label className="form-label">Status</label>
                            <select className="form-select" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label className="form-label">Case no.</label>
                        <input className="form-control" value={form.case_no} onChange={(e) => setForm({ ...form, case_no: e.target.value })} />
                    </div>
                    <p className="small text-muted mb-0">Set the franchise's stop list and fares from its Fare Matrix grid.</p>
                </form>
            </Modal>
        </>
    );
}
