/** Presentation helpers shared by the document library's pieces. */

export const FILE_KINDS = {
    pdf: { label: 'PDF', icon: 'bi-file-earmark-pdf-fill' },
    image: { label: 'Image', icon: 'bi-file-earmark-image-fill' },
    document: { label: 'Document', icon: 'bi-file-earmark-word-fill' },
    spreadsheet: { label: 'Spreadsheet', icon: 'bi-file-earmark-spreadsheet-fill' },
    presentation: { label: 'Presentation', icon: 'bi-file-earmark-slides-fill' },
    other: { label: 'File', icon: 'bi-file-earmark-fill' },
};

export const STATUS = {
    valid: { label: 'Valid', cls: 'is-valid', icon: 'bi-check-circle' },
    expiring: { label: 'Expiring soon', cls: 'is-expiring', icon: 'bi-hourglass-split' },
    expired: { label: 'Expired', cls: 'is-expired', icon: 'bi-exclamation-octagon' },
};

export const SORTS = [
    { key: 'newest', label: 'Newest first' },
    { key: 'oldest', label: 'Oldest first' },
    { key: 'name', label: 'Name (A–Z)' },
    { key: 'expiry', label: 'Expiry date' },
];

export const ACCEPT = '.pdf,.jpg,.jpeg,.png,.webp,.gif,.doc,.docx,.txt,.rtf,.odt,.xls,.xlsx,.csv,.ods,.ppt,.pptx,.odp';
export const MAX_BYTES = 25 * 1024 * 1024;

const EXT_KIND = Object.entries({
    pdf: ['pdf'],
    image: ['jpg', 'jpeg', 'png', 'webp', 'gif'],
    document: ['doc', 'docx', 'txt', 'rtf', 'odt'],
    spreadsheet: ['xls', 'xlsx', 'csv', 'ods'],
    presentation: ['ppt', 'pptx', 'odp'],
}).reduce((map, [kind, exts]) => { exts.forEach((e) => { map[e] = kind; }); return map; }, {});

export function kindOfFileName(name = '') {
    return EXT_KIND[name.split('.').pop()?.toLowerCase()] ?? 'other';
}

export function isPreviewable(doc) {
    return /^(application\/pdf|image\/)/.test(doc.mime_type ?? '');
}

export function formatSize(bytes = 0) {
    if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
    if (bytes >= 1024 ** 2) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

export function formatDate(v) {
    return v ? new Date(v).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : null;
}

/** "in 12 days" / "3 days ago" / "today", for expiry dates. */
export function relativeDays(dateString) {
    if (!dateString) return null;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const days = Math.round((new Date(`${dateString}T00:00:00`) - today) / 86_400_000);
    if (days === 0) return 'today';
    const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
    return Math.abs(days) >= 60 ? rtf.format(Math.round(days / 30), 'month') : rtf.format(days, 'day');
}

/** The file name without its extension, tidied into a default document name. */
export function nameFromFile(fileName = '') {
    return fileName.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim();
}
