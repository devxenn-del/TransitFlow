import { useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import DataTable from '../../components/DataTable.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import StatusBadge from '../../components/StatusBadge.jsx';
import { passengerTypes } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

const BLANK = { name: '', fare_mode: 'Fare Matrix', discount_percent: 0, sort_order: 10, status: 'Active' };

export default function PassengerTypes() {
    const { can } = useAuth();
    const editable = can('passengertypes.edit');
    const { rows, meta, loading, page, setPage, reload } = useList(passengerTypes.list, { perPage: 50 });

    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(BLANK);
    const [saving, setSaving] = useState(false);

    // Articles modal
    const [articlesFor, setArticlesFor] = useState(null);
    const [articleForm, setArticleForm] = useState({ label: '', amount: '' });
    const [articleBusy, setArticleBusy] = useState(false);

    const openNew = () => { setForm(BLANK); setEditing({}); };
    const openEdit = (t) => {
        setForm({ name: t.name, fare_mode: t.fare_mode, discount_percent: t.discount_percent, sort_order: t.sort_order, status: t.status });
        setEditing(t);
    };

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const payload = { ...form, discount_percent: form.fare_mode === 'Manual Amount' ? 0 : Number(form.discount_percent) };
            if (editing.id) {
                await passengerTypes.update(editing.id, payload);
                notifySuccess('Passenger type updated.');
            } else {
                await passengerTypes.create(payload);
                notifySuccess('Passenger type added.');
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
        if (!(await confirmAction({ title: `Delete "${t.name}"?`, text: 'Its article presets are deleted too.', danger: true, confirmText: 'Delete' }))) return;
        try {
            await passengerTypes.remove(t.id);
            notifySuccess('Passenger type deleted.');
            reload();
        } catch (err) {
            notifyError(err);
        }
    };

    const addArticle = async (e) => {
        e.preventDefault();
        setArticleBusy(true);
        try {
            await passengerTypes.addArticle(articlesFor.id, { label: articleForm.label, amount: Number(articleForm.amount) });
            setArticleForm({ label: '', amount: '' });
            notifySuccess('Article added.');
            reload();
            // refresh the open modal's list
            const fresh = (await passengerTypes.list({ per_page: 50 })).data.find((t) => t.id === articlesFor.id);
            setArticlesFor(fresh);
        } catch (err) {
            notifyError(err);
        } finally {
            setArticleBusy(false);
        }
    };

    const removeArticle = async (a) => {
        try {
            await passengerTypes.removeArticle(articlesFor.id, a.id);
            notifySuccess('Article removed.');
            reload();
            const fresh = (await passengerTypes.list({ per_page: 50 })).data.find((t) => t.id === articlesFor.id);
            setArticlesFor(fresh);
        } catch (err) {
            notifyError(err);
        }
    };

    const columns = [
        {
            key: 'name',
            header: 'Passenger type',
            render: (t) => (
                <div>
                    <span className="fw-semibold">{t.name}</span>
                    {t.fare_mode === 'Manual Amount' && (
                        <span className="badge tf-badge-maintenance ms-2">Manual amount</span>
                    )}
                </div>
            ),
        },
        {
            key: 'discount',
            header: 'Discount',
            render: (t) =>
                t.fare_mode === 'Manual Amount' ? (
                    <span className="text-muted">n/a</span>
                ) : t.discount_percent > 0 ? (
                    <span className="fare-amount">{t.discount_percent}%</span>
                ) : (
                    <span className="text-muted">none</span>
                ),
        },
        {
            key: 'articles',
            header: 'Articles',
            render: (t) =>
                t.fare_mode === 'Manual Amount' ? (
                    <button
                        className="btn btn-sm btn-outline-secondary"
                        onClick={() => { setArticlesFor(t); setArticleForm({ label: '', amount: '' }); }}
                    >
                        <i className="bi bi-tags me-1" /> {t.articles_count ?? 0} preset{(t.articles_count ?? 0) === 1 ? '' : 's'}
                    </button>
                ) : (
                    <span className="text-muted">—</span>
                ),
        },
        { key: 'sort_order', header: 'Order' },
        { key: 'status', header: 'Status', render: (t) => <StatusBadge value={t.status.toLowerCase()} /> },
        {
            key: 'actions',
            header: '',
            className: 'text-end text-nowrap',
            render: (t) => (
                <>
                    {editable && (
                        <button className="btn btn-sm btn-outline-secondary me-1" onClick={() => openEdit(t)}>
                            <i className="bi bi-pencil" />
                        </button>
                    )}
                    {can('passengertypes.delete') && (
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
                title="Passenger Types"
                subtitle="Drives the fare a ticket charges — a discount % off the fare matrix, or a manual amount / article preset."
                actions={can('passengertypes.create') && (
                    <button className="btn btn-accent" onClick={openNew}>
                        <i className="bi bi-plus-lg me-1" /> Add type
                    </button>
                )}
            />

            <DataTable columns={columns} rows={rows} loading={loading} empty="No passenger types yet." />
            <Pagination meta={meta} page={page} onPage={setPage} />

            {/* Create / edit passenger type */}
            <Modal
                open={!!editing}
                title={editing?.id ? `Edit "${editing.name}"` : 'Add passenger type'}
                onClose={() => setEditing(null)}
                footer={
                    <>
                        <button className="btn btn-light" onClick={() => setEditing(null)}>Cancel</button>
                        <button className="btn btn-primary" form="pt-form" disabled={saving}>
                            {saving && <span className="spinner-border spinner-border-sm me-2" />}Save
                        </button>
                    </>
                }
            >
                <form id="pt-form" onSubmit={save} className="vstack gap-3">
                    <div>
                        <label className="form-label">Name *</label>
                        <input className="form-control" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                    </div>
                    <div>
                        <label className="form-label">Fare mode *</label>
                        <select className="form-select" value={form.fare_mode} onChange={(e) => setForm({ ...form, fare_mode: e.target.value })}>
                            <option value="Fare Matrix">Fare Matrix — % discount off the route fare</option>
                            <option value="Manual Amount">Manual Amount — conductor enters / picks an amount</option>
                        </select>
                    </div>
                    <div className="row g-2">
                        <div className="col-md-6">
                            <label className="form-label">Discount %</label>
                            <input
                                type="number" step="0.01" min="0" max="100"
                                className="form-control"
                                disabled={form.fare_mode === 'Manual Amount'}
                                value={form.fare_mode === 'Manual Amount' ? 0 : form.discount_percent}
                                onChange={(e) => setForm({ ...form, discount_percent: e.target.value })}
                            />
                        </div>
                        <div className="col-md-3">
                            <label className="form-label">Order</label>
                            <input type="number" className="form-control" value={form.sort_order} onChange={(e) => setForm({ ...form, sort_order: Number(e.target.value) })} />
                        </div>
                        <div className="col-md-3">
                            <label className="form-label">Status</label>
                            <select className="form-select" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    {form.fare_mode === 'Manual Amount' && (
                        <p className="small text-muted mb-0">
                            Add fixed-price "articles" (e.g. Student ₱10) from the type's <i className="bi bi-tags mx-1" /> button after saving.
                        </p>
                    )}
                </form>
            </Modal>

            {/* Manage articles */}
            <Modal
                open={!!articlesFor}
                title={articlesFor ? `Articles — ${articlesFor.name}` : ''}
                onClose={() => setArticlesFor(null)}
                footer={<button className="btn btn-light" onClick={() => setArticlesFor(null)}>Done</button>}
            >
                <p className="small text-muted">
                    When a Manual Amount type has articles, a ticket must pick one and its amount is used — the conductor can't type a free amount.
                </p>
                <ul className="list-group mb-3">
                    {(articlesFor?.articles ?? []).length === 0 && (
                        <li className="list-group-item text-muted small">No articles — the conductor types a free amount.</li>
                    )}
                    {(articlesFor?.articles ?? []).map((a) => (
                        <li key={a.id} className="list-group-item d-flex justify-content-between align-items-center">
                            <span>{a.label} · <span className="fare-amount">₱{Number(a.amount).toFixed(2)}</span></span>
                            {editable && (
                                <button className="btn btn-sm btn-outline-danger" onClick={() => removeArticle(a)}>
                                    <i className="bi bi-x-lg" />
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
                {editable && (
                    <form onSubmit={addArticle} className="row g-2 align-items-end">
                        <div className="col-6">
                            <label className="form-label">Label</label>
                            <input className="form-control form-control-sm" required value={articleForm.label} onChange={(e) => setArticleForm({ ...articleForm, label: e.target.value })} />
                        </div>
                        <div className="col-4">
                            <label className="form-label">Amount (₱)</label>
                            <input type="number" step="0.01" min="0" className="form-control form-control-sm" required value={articleForm.amount} onChange={(e) => setArticleForm({ ...articleForm, amount: e.target.value })} />
                        </div>
                        <div className="col-2">
                            <button className="btn btn-sm btn-primary w-100" disabled={articleBusy}>
                                {articleBusy ? <span className="spinner-border spinner-border-sm" /> : <i className="bi bi-plus-lg" />}
                            </button>
                        </div>
                    </form>
                )}
            </Modal>
        </>
    );
}
