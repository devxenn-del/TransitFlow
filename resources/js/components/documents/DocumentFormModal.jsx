import { useEffect, useRef, useState } from 'react';

import Modal from '../Modal.jsx';
import { ACCEPT, FILE_KINDS, MAX_BYTES, formatSize, kindOfFileName, nameFromFile } from './documentMeta.js';

const blank = (category) => ({
    name: '', category, reference_number: '', issued_at: '', expires_at: '', description: '', is_important: false, file: null,
});

/**
 * Upload a new document, or edit one's details (and optionally replace its
 * file). `document` null = closed, {} = new, a document = edit.
 */
export default function DocumentFormModal({ document, categories, defaultCategory, onClose, onSubmit }) {
    const isEdit = !!document?.id;
    const inputRef = useRef(null);
    const [form, setForm] = useState(blank(defaultCategory));
    const [dragging, setDragging] = useState(false);
    const [fileError, setFileError] = useState('');
    const [saving, setSaving] = useState(false);
    const [nameTouched, setNameTouched] = useState(false);

    useEffect(() => {
        if (!document) return;
        setFileError('');
        setNameTouched(isEdit);
        setForm(isEdit ? {
            name: document.name,
            category: document.category,
            reference_number: document.reference_number ?? '',
            issued_at: document.issued_at ?? '',
            expires_at: document.expires_at ?? '',
            description: document.description ?? '',
            is_important: !!document.is_important,
            file: null,
        } : blank(defaultCategory ?? categories[0]?.key));
        // Reset only when a different document is opened, not on parent re-renders.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [document]);

    const set = (key, value) => setForm((f) => ({ ...f, [key]: value }));

    const pickFile = (file) => {
        if (!file) return;
        if (file.size > MAX_BYTES) {
            setFileError(`${file.name} is ${formatSize(file.size)} — the limit is 25 MB.`);
            return;
        }
        setFileError('');
        setForm((f) => ({ ...f, file, name: nameTouched && f.name ? f.name : nameFromFile(file.name) }));
    };

    const onDrop = (e) => {
        e.preventDefault();
        setDragging(false);
        pickFile(e.dataTransfer.files?.[0]);
    };

    const submit = async (e) => {
        e.preventDefault();
        if (!isEdit && !form.file) {
            setFileError('Choose a file to upload.');
            return;
        }
        setSaving(true);
        try {
            await onSubmit({
                ...form,
                reference_number: form.reference_number || null,
                issued_at: form.issued_at || null,
                expires_at: form.expires_at || null,
                file: form.file ?? undefined,
            });
        } finally {
            setSaving(false);
        }
    };

    const kind = form.file ? FILE_KINDS[kindOfFileName(form.file.name)] : null;

    return (
        <Modal
            open={!!document}
            size="lg"
            title={isEdit ? 'Edit document' : 'Upload document'}
            onClose={onClose}
            footer={(
                <>
                    <button type="button" className="btn btn-light" onClick={onClose}>Cancel</button>
                    <button className="btn btn-primary px-4" form="document-form" disabled={saving}>
                        {saving && <span className="spinner-border spinner-border-sm me-2" />}
                        {isEdit ? 'Save changes' : 'Upload'}
                    </button>
                </>
            )}
        >
            <form id="document-form" onSubmit={submit} className="vstack gap-3">
                <div
                    className={`tf-dropzone${dragging ? ' is-dragging' : ''}${form.file ? ' has-file' : ''}`}
                    onDragOver={(e) => { e.preventDefault(); setDragging(true); }}
                    onDragLeave={() => setDragging(false)}
                    onDrop={onDrop}
                    onClick={() => inputRef.current?.click()}
                    role="button"
                    tabIndex={0}
                    onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && inputRef.current?.click()}
                >
                    <input ref={inputRef} type="file" accept={ACCEPT} hidden onChange={(e) => { pickFile(e.target.files?.[0]); e.target.value = ''; }} />
                    {form.file ? (
                        <div className="d-flex align-items-center gap-3 text-start w-100">
                            <span className={`tf-file-tile is-${kindOfFileName(form.file.name)}`}><i className={`bi ${kind.icon}`} /></span>
                            <div className="flex-grow-1 min-w-0">
                                <div className="fw-semibold text-truncate">{form.file.name}</div>
                                <div className="small text-body-secondary">{kind.label} · {formatSize(form.file.size)}</div>
                            </div>
                            <button type="button" className="btn btn-sm btn-light" onClick={(e) => { e.stopPropagation(); set('file', null); }} aria-label="Remove file">
                                <i className="bi bi-x-lg" />
                            </button>
                        </div>
                    ) : (
                        <>
                            <i className="bi bi-cloud-arrow-up-fill tf-dropzone-icon" />
                            <div className="fw-semibold">{isEdit ? 'Drop a new file to replace the current one' : 'Drag & drop a file here'}</div>
                            <div className="small text-body-secondary">
                                or <span className="text-primary fw-medium">browse</span> — PDF, images, Word, Excel, PowerPoint or CSV, up to 25 MB
                            </div>
                            {isEdit && <div className="small text-body-tertiary mt-1">Current file: {document.original_name}</div>}
                        </>
                    )}
                </div>
                {fileError && <div className="text-danger small mt-n2">{fileError}</div>}

                <div className="row g-3">
                    <div className="col-md-7">
                        <label className="form-label">Document name <span className="text-danger">*</span></label>
                        <input className="form-control" required maxLength={150} value={form.name}
                            onChange={(e) => { setNameTouched(true); set('name', e.target.value); }}
                            placeholder="e.g. LTFRB Franchise — Route 12" />
                    </div>
                    <div className="col-md-5">
                        <label className="form-label">Category <span className="text-danger">*</span></label>
                        <select className="form-select" value={form.category} onChange={(e) => set('category', e.target.value)}>
                            {categories.map((c) => <option key={c.key} value={c.key}>{c.label}</option>)}
                        </select>
                    </div>
                    <div className="col-md-4">
                        <label className="form-label">Reference / document no.</label>
                        <input className="form-control" maxLength={100} value={form.reference_number} onChange={(e) => set('reference_number', e.target.value)} placeholder="Optional" />
                    </div>
                    <div className="col-6 col-md-4">
                        <label className="form-label">Issued on</label>
                        <input type="date" className="form-control" value={form.issued_at} onChange={(e) => set('issued_at', e.target.value)} />
                    </div>
                    <div className="col-6 col-md-4">
                        <label className="form-label">Expires on</label>
                        <input type="date" className="form-control" min={form.issued_at || undefined} value={form.expires_at} onChange={(e) => set('expires_at', e.target.value)} />
                    </div>
                    <div className="col-12">
                        <label className="form-label">Notes</label>
                        <textarea className="form-control" rows={2} maxLength={2000} value={form.description} onChange={(e) => set('description', e.target.value)} placeholder="What is this document for? Anything to remember about it?" />
                    </div>
                    <div className="col-12">
                        <label className="tf-important-toggle">
                            <input type="checkbox" className="form-check-input m-0" checked={form.is_important} onChange={(e) => set('is_important', e.target.checked)} />
                            <i className={`bi ${form.is_important ? 'bi-star-fill' : 'bi-star'}`} />
                            <span>
                                <span className="fw-semibold d-block">Mark as important</span>
                                <span className="small text-body-secondary">Important documents are pinned to the top of the library and have their own filter.</span>
                            </span>
                        </label>
                    </div>
                </div>
            </form>
        </Modal>
    );
}
