import { useCallback, useEffect, useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import DocumentDrawer from '../../components/documents/DocumentDrawer.jsx';
import DocumentFormModal from '../../components/documents/DocumentFormModal.jsx';
import { FILE_KINDS, SORTS, STATUS, formatDate, formatSize, isPreviewable, relativeDays } from '../../components/documents/documentMeta.js';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import { companyDocuments } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

const PER_PAGE = 6;
const LAYOUT_KEY = 'tf.documents.layout';

const VIEWS = [
    { key: 'all', label: 'All documents', icon: 'bi-folder2-open', count: (s) => s.total },
    { key: 'important', label: 'Important', icon: 'bi-star-fill', count: (s) => s.important },
    { key: 'expiring', label: 'Expiring soon', icon: 'bi-hourglass-split', count: (s) => s.expiring, tone: 'warning' },
    { key: 'expired', label: 'Expired', icon: 'bi-exclamation-octagon', count: (s) => s.expired, tone: 'danger' },
    { key: 'no_expiry', label: 'No expiration', icon: 'bi-infinity' },
];

function readLayout() {
    try { return localStorage.getItem(LAYOUT_KEY) === 'list' ? 'list' : 'grid'; } catch { return 'grid'; }
}

function StarButton({ doc, canManage, onToggle }) {
    if (!canManage) {
        return doc.is_important ? <i className="bi bi-star-fill tf-star is-on" title="Important" /> : null;
    }
    return (
        <button
            type="button"
            className={`tf-star-btn${doc.is_important ? ' is-on' : ''}`}
            title={doc.is_important ? 'Unmark important' : 'Mark as important'}
            aria-pressed={doc.is_important}
            onClick={(e) => { e.stopPropagation(); onToggle(doc); }}
        >
            <i className={`bi ${doc.is_important ? 'bi-star-fill' : 'bi-star'}`} />
        </button>
    );
}

function ExpiryChip({ doc }) {
    if (!doc.expires_at) return <span className="tf-status-chip is-none"><i className="bi bi-infinity" />No expiry</span>;
    const status = STATUS[doc.status] ?? STATUS.valid;
    const rel = relativeDays(doc.expires_at);
    return (
        <span className={`tf-status-chip ${status.cls}`} title={`Expires ${formatDate(doc.expires_at)}`}>
            <i className={`bi ${status.icon}`} />
            {doc.status === 'expired' ? `Expired ${rel}` : `Expires ${rel}`}
        </span>
    );
}

function RowMenu({ doc, canManage, actions }) {
    return (
        <div className="dropdown" onClick={(e) => e.stopPropagation()}>
            <button type="button" className="btn btn-sm tf-icon-btn" data-bs-toggle="dropdown" aria-label={`Actions for ${doc.name}`}>
                <i className="bi bi-three-dots-vertical" />
            </button>
            <ul className="dropdown-menu dropdown-menu-end shadow-sm">
                {isPreviewable(doc) && <li><button className="dropdown-item" onClick={() => actions.preview(doc)}><i className="bi bi-eye me-2" />Preview</button></li>}
                <li><button className="dropdown-item" onClick={() => actions.download(doc)}><i className="bi bi-download me-2" />Download</button></li>
                {canManage && (
                    <>
                        <li><button className="dropdown-item" onClick={() => actions.edit(doc)}><i className="bi bi-pencil me-2" />Edit / replace file</button></li>
                        <li><hr className="dropdown-divider" /></li>
                        <li><button className="dropdown-item text-danger" onClick={() => actions.remove(doc)}><i className="bi bi-trash me-2" />Delete</button></li>
                    </>
                )}
            </ul>
        </div>
    );
}

function DocumentCard({ doc, canManage, actions }) {
    const kind = FILE_KINDS[doc.file_kind] ?? FILE_KINDS.other;
    return (
        <article
            className={`tf-doc-card${doc.is_important ? ' is-important' : ''}`}
            tabIndex={0}
            onClick={() => actions.open(doc)}
            onKeyDown={(e) => e.key === 'Enter' && e.target === e.currentTarget && actions.open(doc)}
        >
            <div className="tf-doc-card-top">
                <span className={`tf-file-tile is-${doc.file_kind}`}><i className={`bi ${kind.icon}`} /></span>
                <span className="tf-doc-ext">{doc.extension || kind.label}</span>
                <div className="ms-auto d-flex align-items-center">
                    <StarButton doc={doc} canManage={canManage} onToggle={actions.toggleImportant} />
                    <RowMenu doc={doc} canManage={canManage} actions={actions} />
                </div>
            </div>
            <h3 className="tf-doc-name" title={doc.name}>{doc.name}</h3>
            <div className="tf-doc-sub">
                <span>{doc.category_label}</span>
                {doc.reference_number && <span className="tf-mono">#{doc.reference_number}</span>}
            </div>
            <div className="tf-doc-foot">
                <ExpiryChip doc={doc} />
                <span className="small text-body-secondary text-nowrap">{formatSize(doc.size)}</span>
            </div>
            <div className="tf-doc-meta">
                <i className="bi bi-person" />{doc.uploaded_by?.name ?? 'Unknown'} · {formatDate(doc.created_at)}
            </div>
        </article>
    );
}

function DocumentTable({ rows, canManage, actions }) {
    return (
        <div className="card border-0 shadow-sm">
            <div className="table-responsive">
                <table className="table table-hover align-middle mb-0 tf-doc-table">
                    <thead>
                        <tr>
                            <th style={{ width: 44 }}><span className="visually-hidden">Important</span></th>
                            <th>Document</th>
                            <th>Category</th>
                            <th>Reference</th>
                            <th>Expiration</th>
                            <th>Uploaded</th>
                            <th className="text-end"><span className="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((doc) => {
                            const kind = FILE_KINDS[doc.file_kind] ?? FILE_KINDS.other;
                            return (
                                <tr key={doc.id} className="tf-row-link" onClick={() => actions.open(doc)}>
                                    <td><StarButton doc={doc} canManage={canManage} onToggle={actions.toggleImportant} /></td>
                                    <td style={{ minWidth: 240 }}>
                                        <div className="d-flex align-items-center gap-2">
                                            <span className={`tf-file-tile is-sm is-${doc.file_kind}`}><i className={`bi ${kind.icon}`} /></span>
                                            <div className="min-w-0">
                                                <div className="fw-semibold text-truncate" style={{ maxWidth: 320 }}>{doc.name}</div>
                                                <div className="small text-body-secondary">{(doc.extension || kind.label).toUpperCase()} · {formatSize(doc.size)}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span className="tf-chip">{doc.category_label}</span></td>
                                    <td className="small tf-mono">{doc.reference_number || <span className="text-body-tertiary">—</span>}</td>
                                    <td className="text-nowrap"><ExpiryChip doc={doc} /></td>
                                    <td className="small text-nowrap">
                                        {formatDate(doc.created_at)}
                                        <div className="text-body-secondary">{doc.uploaded_by?.name ?? '—'}</div>
                                    </td>
                                    <td className="text-end"><RowMenu doc={doc} canManage={canManage} actions={actions} /></td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

/**
 * The company's document library — every kind of company paper, not only
 * franchises. Filter rail (views + categories with counts), search, file
 * type and sort, grid or table layout, "important" starring, and a details
 * panel.
 */
export default function CompanyDocuments() {
    const { can } = useAuth();
    const canManage = can('documents.manage');

    const [summary, setSummary] = useState(null);
    const [search, setSearch] = useState('');
    const [filters, setFilters] = useState({ view: 'all', category: '', q: '', file_kind: '', sort: 'newest' });
    const [layout, setLayout] = useState(readLayout);
    const [editing, setEditing] = useState(null);
    const [openDoc, setOpenDoc] = useState(null);

    const { rows, meta, loading, page, setPage, reload } = useList(
        (params) => companyDocuments.list({
            ...params,
            q: filters.q || undefined,
            category: filters.category || undefined,
            file_kind: filters.file_kind || undefined,
            sort: filters.sort,
            important: filters.view === 'important' ? 1 : undefined,
            status: ['expiring', 'expired', 'no_expiry'].includes(filters.view) ? filters.view : undefined,
        }),
        { deps: [filters], perPage: PER_PAGE },
    );

    const loadSummary = useCallback(() => {
        companyDocuments.summary().then(setSummary).catch(() => {});
    }, []);
    useEffect(loadSummary, [loadSummary]);

    const refresh = () => { reload(); loadSummary(); };

    useEffect(() => {
        const t = setTimeout(() => {
            setFilters((f) => (f.q === search.trim() ? f : { ...f, q: search.trim() }));
        }, 300);
        return () => clearTimeout(t);
    }, [search]);

    // Any filter change starts from page 1.
    useEffect(() => { setPage(1); }, [filters, setPage]);

    const setFilter = (key, value) => setFilters((f) => ({ ...f, [key]: value }));
    const clearFilters = () => { setSearch(''); setFilters((f) => ({ ...f, view: 'all', category: '', q: '', file_kind: '' })); };
    const changeLayout = (next) => {
        setLayout(next);
        try { localStorage.setItem(LAYOUT_KEY, next); } catch { /* per-viewer convenience only */ }
    };

    const safely = (fn, fallback) => fn().catch((err) => notifyError(err, fallback));

    const actions = {
        open: (doc) => setOpenDoc(doc),
        preview: (doc) => safely(() => companyDocuments.preview(doc), 'Could not open the file.'),
        download: (doc) => safely(() => companyDocuments.download(doc), 'Could not download the file.'),
        edit: (doc) => { setOpenDoc(null); setEditing(doc); },
        toggleImportant: (doc) => safely(async () => {
            const updated = await companyDocuments.setImportant(doc.id, !doc.is_important);
            if (openDoc?.id === doc.id) setOpenDoc(updated);
            notifySuccess(updated.is_important ? 'Marked as important.' : 'Removed from important.');
            refresh();
        }),
        remove: async (doc) => {
            if (!(await confirmAction({ title: `Delete "${doc.name}"?`, text: 'The file will be permanently removed. This cannot be undone.', danger: true, confirmText: 'Delete document' }))) return;
            safely(async () => {
                await companyDocuments.remove(doc.id);
                setOpenDoc(null);
                notifySuccess('Document deleted.');
                refresh();
            });
        },
    };

    const save = async (payload) => {
        try {
            if (editing.id) {
                const updated = await companyDocuments.update(editing.id, payload);
                notifySuccess(payload.file ? 'Document updated and file replaced.' : 'Document updated.');
                setOpenDoc(updated);
            } else {
                await companyDocuments.create(payload);
                notifySuccess('Document uploaded.');
            }
            setEditing(null);
            refresh();
        } catch (err) {
            notifyError(err);
        }
    };

    const categories = summary?.categories ?? [];
    const activeCategory = categories.find((c) => c.key === filters.category);
    const activeView = VIEWS.find((v) => v.key === filters.view);
    const hasFilters = filters.view !== 'all' || filters.category || filters.q || filters.file_kind;
    const attention = (summary?.expired ?? 0) + (summary?.expiring ?? 0);

    return (
        <>
            <PageHeader
                eyebrow="Company"
                title="Documents"
                subtitle="Every document your company keeps — franchises, permits, registrations, vehicle records, contracts and more"
                actions={canManage && (
                    <button className="btn btn-primary" onClick={() => setEditing({})}>
                        <i className="bi bi-cloud-arrow-up me-1" /> Upload document
                    </button>
                )}
            />

            {attention > 0 && filters.view !== 'expiring' && filters.view !== 'expired' && (
                <div className="tf-attention mb-3">
                    <i className="bi bi-bell-fill" />
                    <div className="flex-grow-1">
                        <span className="fw-semibold">
                            {summary.expired > 0 && `${summary.expired} expired`}
                            {summary.expired > 0 && summary.expiring > 0 && ' · '}
                            {summary.expiring > 0 && `${summary.expiring} expiring within 30 days`}
                        </span>
                        <span className="text-body-secondary d-none d-sm-inline"> — renew these to stay compliant.</span>
                    </div>
                    <button type="button" className="btn btn-sm btn-light" onClick={() => setFilter('view', summary.expired > 0 ? 'expired' : 'expiring')}>
                        Review
                    </button>
                </div>
            )}

            <div className="tf-doc-layout">
                {/* Filter rail (desktop) */}
                <aside className="tf-doc-rail d-none d-lg-block">
                    <div className="tf-rail-label">Library</div>
                    {VIEWS.map((v) => (
                        <button key={v.key} type="button" className={`tf-rail-item${filters.view === v.key ? ' active' : ''}${v.tone ? ` is-${v.tone}` : ''}`} onClick={() => setFilter('view', v.key)}>
                            <i className={`bi ${v.icon}`} /><span>{v.label}</span>
                            {summary && v.count && <span className="tf-rail-count">{v.count(summary)}</span>}
                        </button>
                    ))}

                    <div className="tf-rail-label mt-3">Categories</div>
                    {categories.map((c) => (
                        <button key={c.key} type="button" className={`tf-rail-item${filters.category === c.key ? ' active' : ''}${c.count ? '' : ' is-empty'}`}
                            onClick={() => setFilter('category', filters.category === c.key ? '' : c.key)}>
                            <i className="bi bi-folder" /><span>{c.label}</span>
                            <span className="tf-rail-count">{c.count}</span>
                        </button>
                    ))}

                    {summary && (
                        <div className="tf-rail-storage">
                            <i className="bi bi-hdd" /> {formatSize(summary.total_size)} used · {summary.total} {summary.total === 1 ? 'file' : 'files'}
                        </div>
                    )}
                </aside>

                <section className="min-w-0">
                    {/* Toolbar */}
                    <div className="tf-doc-toolbar">
                        <div className="input-group tf-doc-search">
                            <span className="input-group-text bg-body"><i className="bi bi-search" /></span>
                            <input className="form-control" placeholder="Search name, reference no., notes or file name" value={search} onChange={(e) => setSearch(e.target.value)} />
                            {search && <button type="button" className="btn btn-outline-secondary border-start-0" onClick={() => setSearch('')} aria-label="Clear search"><i className="bi bi-x-lg" /></button>}
                        </div>
                        <select className="form-select d-lg-none" aria-label="View" value={filters.view} onChange={(e) => setFilter('view', e.target.value)}>
                            {VIEWS.map((v) => <option key={v.key} value={v.key}>{v.label}</option>)}
                        </select>
                        <select className="form-select d-lg-none" aria-label="Category" value={filters.category} onChange={(e) => setFilter('category', e.target.value)}>
                            <option value="">All categories</option>
                            {categories.map((c) => <option key={c.key} value={c.key}>{c.label} ({c.count})</option>)}
                        </select>
                        <select className="form-select" aria-label="File type" value={filters.file_kind} onChange={(e) => setFilter('file_kind', e.target.value)}>
                            <option value="">All file types</option>
                            {Object.entries(FILE_KINDS).map(([key, k]) => <option key={key} value={key}>{key === 'other' ? 'Other files' : `${k.label}s`}</option>)}
                        </select>
                        <select className="form-select" aria-label="Sort" value={filters.sort} onChange={(e) => setFilter('sort', e.target.value)}>
                            {SORTS.map((s) => <option key={s.key} value={s.key}>{s.label}</option>)}
                        </select>
                        <div className="btn-group tf-layout-toggle" role="group" aria-label="Layout">
                            <button type="button" className={`btn btn-outline-secondary${layout === 'grid' ? ' active' : ''}`} onClick={() => changeLayout('grid')} title="Card view" aria-pressed={layout === 'grid'}>
                                <i className="bi bi-grid-3x3-gap" />
                            </button>
                            <button type="button" className={`btn btn-outline-secondary${layout === 'list' ? ' active' : ''}`} onClick={() => changeLayout('list')} title="Table view" aria-pressed={layout === 'list'}>
                                <i className="bi bi-list-ul" />
                            </button>
                        </div>
                    </div>

                    {hasFilters && (
                        <div className="tf-active-filters">
                            {filters.view !== 'all' && <button type="button" className="tf-filter-pill" onClick={() => setFilter('view', 'all')}>{activeView?.label}<i className="bi bi-x" /></button>}
                            {filters.category && <button type="button" className="tf-filter-pill" onClick={() => setFilter('category', '')}>{activeCategory?.label ?? filters.category}<i className="bi bi-x" /></button>}
                            {filters.file_kind && <button type="button" className="tf-filter-pill" onClick={() => setFilter('file_kind', '')}>{FILE_KINDS[filters.file_kind]?.label}s<i className="bi bi-x" /></button>}
                            {filters.q && <button type="button" className="tf-filter-pill" onClick={() => setSearch('')}>“{filters.q}”<i className="bi bi-x" /></button>}
                            <button type="button" className="btn btn-link btn-sm p-0 ms-1" onClick={clearFilters}>Clear all</button>
                            {meta && <span className="small text-body-secondary ms-auto">{meta.total} {meta.total === 1 ? 'result' : 'results'}</span>}
                        </div>
                    )}

                    {loading && (
                        <div className="d-flex justify-content-center py-5"><div className="spinner-border text-primary" role="status" /></div>
                    )}

                    {!loading && rows.length === 0 && (
                        <div className="tf-doc-empty">
                            <div className="tf-doc-empty-icon"><i className={`bi ${hasFilters ? 'bi-search' : 'bi-folder2-open'}`} /></div>
                            <h3>{hasFilters ? 'No documents match these filters' : 'No documents yet'}</h3>
                            <p>
                                {hasFilters
                                    ? 'Try another category, file type or search term.'
                                    : 'Upload franchises, permits, registrations, OR/CRs, insurance, contracts — anything your company needs to keep on file.'}
                            </p>
                            {hasFilters
                                ? <button type="button" className="btn btn-light" onClick={clearFilters}>Clear filters</button>
                                : canManage && <button type="button" className="btn btn-primary" onClick={() => setEditing({})}><i className="bi bi-cloud-arrow-up me-1" />Upload your first document</button>}
                        </div>
                    )}

                    {!loading && rows.length > 0 && (layout === 'grid' ? (
                        <div className="tf-doc-grid">
                            {rows.map((doc) => <DocumentCard key={doc.id} doc={doc} canManage={canManage} actions={actions} />)}
                        </div>
                    ) : (
                        <DocumentTable rows={rows} canManage={canManage} actions={actions} />
                    ))}

                    <Pagination meta={meta} page={page} onPage={setPage} />
                </section>
            </div>

            <DocumentDrawer
                doc={openDoc}
                canManage={canManage}
                onClose={() => setOpenDoc(null)}
                onPreview={actions.preview}
                onDownload={actions.download}
                onEdit={actions.edit}
                onDelete={actions.remove}
                onToggleImportant={actions.toggleImportant}
            />

            <DocumentFormModal
                document={editing}
                categories={categories}
                defaultCategory={filters.category || 'other'}
                onClose={() => setEditing(null)}
                onSubmit={save}
            />
        </>
    );
}
