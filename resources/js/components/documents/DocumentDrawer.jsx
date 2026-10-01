import { useEffect } from 'react';

import { FILE_KINDS, STATUS, formatDate, formatSize, isPreviewable, relativeDays } from './documentMeta.js';

function Row({ label, children }) {
    return (
        <div className="tf-drawer-row">
            <dt>{label}</dt>
            <dd>{children || <span className="text-body-tertiary">—</span>}</dd>
        </div>
    );
}

/** Slide-in details panel for one document, with its actions. */
export default function DocumentDrawer({ doc, canManage, onClose, onPreview, onDownload, onEdit, onDelete, onToggleImportant }) {
    useEffect(() => {
        if (!doc) return undefined;
        const onKey = (e) => e.key === 'Escape' && onClose();
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [doc, onClose]);

    if (!doc) return null;

    const kind = FILE_KINDS[doc.file_kind] ?? FILE_KINDS.other;
    const status = STATUS[doc.status] ?? STATUS.valid;

    return (
        <>
            <div className="tf-drawer-backdrop" onClick={onClose} />
            <aside className="tf-drawer" role="dialog" aria-modal="true" aria-label={doc.name}>
                <div className="tf-drawer-head">
                    <span className={`tf-file-tile is-${doc.file_kind}`}><i className={`bi ${kind.icon}`} /></span>
                    <div className="flex-grow-1 min-w-0">
                        <h2 className="tf-drawer-title">{doc.name}</h2>
                        <div className="small text-body-secondary text-truncate">{doc.original_name}</div>
                    </div>
                    <button type="button" className="btn-close" aria-label="Close" onClick={onClose} />
                </div>

                <div className="tf-drawer-body">
                    <div className="d-flex flex-wrap gap-2 mb-3">
                        <span className="tf-chip">{doc.category_label}</span>
                        {doc.expires_at && <span className={`tf-status-chip ${status.cls}`}><i className={`bi ${status.icon}`} />{status.label}</span>}
                        {doc.is_important && <span className="tf-status-chip is-important"><i className="bi bi-star-fill" />Important</span>}
                    </div>

                    <div className="d-grid gap-2 mb-4" style={{ gridTemplateColumns: isPreviewable(doc) ? '1fr 1fr' : '1fr' }}>
                        {isPreviewable(doc) && (
                            <button type="button" className="btn btn-outline-secondary" onClick={() => onPreview(doc)}>
                                <i className="bi bi-eye me-1" />Preview
                            </button>
                        )}
                        <button type="button" className="btn btn-primary" onClick={() => onDownload(doc)}>
                            <i className="bi bi-download me-1" />Download
                        </button>
                    </div>

                    <dl className="tf-drawer-list">
                        <Row label="Reference no.">{doc.reference_number}</Row>
                        <Row label="Issued on">{formatDate(doc.issued_at)}</Row>
                        <Row label="Expires on">
                            {doc.expires_at ? <>{formatDate(doc.expires_at)} <span className="text-body-secondary">({relativeDays(doc.expires_at)})</span></> : 'No expiration'}
                        </Row>
                        <Row label="File">{kind.label} · {formatSize(doc.size)}</Row>
                        <Row label="Uploaded">{formatDate(doc.created_at)}{doc.uploaded_by && <> by {doc.uploaded_by.name}</>}</Row>
                        {doc.updated_at !== doc.created_at && <Row label="Last updated">{formatDate(doc.updated_at)}</Row>}
                    </dl>

                    {doc.description && (
                        <div className="mt-3">
                            <div className="tf-drawer-label">Notes</div>
                            <p className="mb-0 small" style={{ whiteSpace: 'pre-wrap' }}>{doc.description}</p>
                        </div>
                    )}
                </div>

                {canManage && (
                    <div className="tf-drawer-foot">
                        <button type="button" className="btn btn-light" onClick={() => onToggleImportant(doc)}>
                            <i className={`bi ${doc.is_important ? 'bi-star-fill text-warning' : 'bi-star'} me-1`} />
                            {doc.is_important ? 'Unmark important' : 'Mark important'}
                        </button>
                        <button type="button" className="btn btn-light" onClick={() => onEdit(doc)}>
                            <i className="bi bi-pencil me-1" />Edit
                        </button>
                        <button type="button" className="btn btn-outline-danger ms-auto" onClick={() => onDelete(doc)} aria-label="Delete document">
                            <i className="bi bi-trash" />
                        </button>
                    </div>
                )}
            </aside>
        </>
    );
}
