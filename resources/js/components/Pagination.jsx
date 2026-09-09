export default function Pagination({ meta, page, onPage }) {
    if (!meta || meta.last_page <= 1) return null;
    return (
        <nav className="d-flex justify-content-between align-items-center mt-3">
            <span className="small text-body-secondary">
                {meta.from}–{meta.to} of {meta.total}
            </span>
            <div className="btn-group btn-group-sm">
                <button className="btn btn-outline-secondary" disabled={page <= 1} onClick={() => onPage(page - 1)}>
                    <i className="bi bi-chevron-left" />
                </button>
                <button className="btn btn-outline-secondary disabled">
                    {meta.current_page} / {meta.last_page}
                </button>
                <button className="btn btn-outline-secondary" disabled={page >= meta.last_page} onClick={() => onPage(page + 1)}>
                    <i className="bi bi-chevron-right" />
                </button>
            </div>
        </nav>
    );
}
