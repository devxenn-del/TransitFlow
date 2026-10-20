/**
 * Skeleton loading placeholders — the app's one way to show data that is
 * still on its way (tables, cards, lists, whole pages). Shapes mirror the
 * real layout so nothing jumps when the data lands. Button / action spinners
 * stay as they are; these are for content.
 */

/** One shimmering bar. `width` / `height` take any CSS length. */
export function Skeleton({ width = '100%', height = '0.8rem', circle = false, className = '', style }) {
    return (
        <span
            className={`tf-skel${circle ? ' tf-skel-circle' : ''} ${className}`}
            style={{ width, height: circle ? width : height, ...style }}
            aria-hidden="true"
        />
    );
}

/** A few lines of text, the last one shorter. */
export function SkeletonText({ lines = 3, className = '' }) {
    return (
        <div className={`tf-skel-text ${className}`} aria-hidden="true">
            {Array.from({ length: lines }, (_, i) => (
                <Skeleton key={i} width={i === lines - 1 && lines > 1 ? '60%' : '100%'} />
            ))}
        </div>
    );
}

const CELL_WIDTHS = ['72%', '55%', '84%', '46%', '64%', '38%', '58%'];

/** `<tr>` placeholders for a table body. */
export function SkeletonTableRows({ columns, rows = 6 }) {
    return Array.from({ length: rows }, (_, r) => (
        <tr key={`skel-${r}`} className="tf-skel-row" aria-hidden="true">
            {Array.from({ length: columns }, (_, c) => (
                <td key={c}>
                    <Skeleton width={CELL_WIDTHS[(r + c) % CELL_WIDTHS.length]} />
                </td>
            ))}
        </tr>
    ));
}

/** A complete table card, for pages that render their own table. */
export function SkeletonTable({ columns = 5, rows = 6 }) {
    return (
        <div className="card" role="status" aria-label="Loading">
            <div className="table-responsive">
                <table className="table align-middle mb-0">
                    <thead>
                        <tr>
                            {Array.from({ length: columns }, (_, c) => (
                                <th key={c}><Skeleton width="50%" height="0.6rem" /></th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        <SkeletonTableRows columns={columns} rows={rows} />
                    </tbody>
                </table>
            </div>
        </div>
    );
}

/** Stat tiles (`tf-stat`) in the dashboard grid. */
export function SkeletonStats({ count = 4, colClassName = 'col-sm-6 col-xl-3' }) {
    return Array.from({ length: count }, (_, i) => (
        <div key={`skel-stat-${i}`} className={colClassName}>
            <div className="tf-stat" aria-hidden="true">
                <Skeleton width="44px" height="44px" className="tf-skel-tile" />
                <div className="flex-fill">
                    <Skeleton width="45%" height="1.15rem" />
                    <Skeleton width="70%" height="0.6rem" className="mt-2" />
                </div>
            </div>
        </div>
    ));
}

/** A card with a heading and body lines. */
export function SkeletonCard({ lines = 4, className = '' }) {
    return (
        <div className={`card ${className}`} aria-hidden="true">
            <div className="card-body">
                <Skeleton width="35%" height="0.7rem" className="mb-3" />
                <SkeletonText lines={lines} />
            </div>
        </div>
    );
}

/** Label + input pairs, for settings / profile forms. */
export function SkeletonForm({ fields = 6 }) {
    return (
        <div className="card" aria-hidden="true">
            <div className="card-body">
                <div className="row g-3">
                    {Array.from({ length: fields }, (_, i) => (
                        <div key={i} className="col-md-6">
                            <Skeleton width="30%" height="0.6rem" className="mb-2" />
                            <Skeleton height="2.3rem" className="tf-skel-input" />
                        </div>
                    ))}
                </div>
                <Skeleton width="120px" height="2.3rem" className="tf-skel-input mt-4" />
            </div>
        </div>
    );
}

/** Rows of avatar + two lines, for feeds and "recent …" lists. */
export function SkeletonList({ items = 5 }) {
    return (
        <ul className="tf-skel-list" aria-hidden="true">
            {Array.from({ length: items }, (_, i) => (
                <li key={i}>
                    <Skeleton width="34px" circle />
                    <div className="flex-fill">
                        <Skeleton width={CELL_WIDTHS[i % CELL_WIDTHS.length]} />
                        <Skeleton width="40%" height="0.6rem" className="mt-2" />
                    </div>
                </li>
            ))}
        </ul>
    );
}

/** A grid of tiles (document cards, buses on the live board, …). */
export function SkeletonGrid({ count = 6, colClassName = 'col-sm-6 col-lg-4' }) {
    return (
        <div className="row g-3" aria-hidden="true">
            {Array.from({ length: count }, (_, i) => (
                <div key={i} className={colClassName}>
                    <SkeletonCard lines={2} />
                </div>
            ))}
        </div>
    );
}

/** The page header block: eyebrow, title, subtitle (mirrors PageHeader). */
export function SkeletonPageHeader() {
    return (
        <div className="tf-page-head" aria-hidden="true">
            <div className="flex-fill">
                <Skeleton width="90px" height="0.6rem" />
                <Skeleton width="min(320px, 70%)" height="1.8rem" className="mt-2" />
                <Skeleton width="min(240px, 50%)" height="0.7rem" className="mt-2" />
            </div>
        </div>
    );
}

/** The "Your access" card (mirrors AccessSummary): head row + module tiles. */
export function SkeletonAccessSummary({ modules = 8 }) {
    return (
        <section className="card tf-access" aria-hidden="true">
            <div className="tf-access-head">
                <Skeleton width="40px" height="40px" className="tf-skel-tile" />
                <div className="flex-grow-1 min-w-0">
                    <Skeleton width="120px" height="0.95rem" />
                    <Skeleton width="min(280px, 80%)" height="0.65rem" className="mt-2" />
                </div>
                <div className="tf-access-stats">
                    <div><Skeleton width="34px" height="1.1rem" /><Skeleton width="54px" height="0.55rem" className="mt-1" /></div>
                    <div><Skeleton width="34px" height="1.1rem" /><Skeleton width="54px" height="0.55rem" className="mt-1" /></div>
                </div>
            </div>
            <div className="tf-access-body">
                <div className="tf-access-area">
                    <Skeleton width="80px" height="0.6rem" className="mb-2" />
                    <div className="tf-access-grid">
                        {Array.from({ length: modules }, (_, i) => (
                            <div key={i} className="tf-access-module">
                                <Skeleton width="28px" height="28px" className="tf-skel-tile" />
                                <div className="flex-fill min-w-0">
                                    <Skeleton width={CELL_WIDTHS[i % CELL_WIDTHS.length]} height="0.75rem" />
                                    <div className="d-flex gap-1 mt-2">
                                        <Skeleton width="38px" height="1rem" />
                                        <Skeleton width="38px" height="1rem" />
                                        <Skeleton width="38px" height="1rem" />
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </section>
    );
}

/**
 * A whole page that is still loading: header, then the body shape —
 * `form` (settings / profile), `detail` (stats + cards), `table` or `map`.
 */
export function SkeletonPage({ variant = 'detail', header = true }) {
    return (
        <div className="tf-skel-page" role="status" aria-label="Loading">
            {header && <SkeletonPageHeader />}
            {variant === 'form' && <SkeletonForm />}
            {variant === 'table' && <SkeletonTable />}
            {variant === 'map' && <Skeleton height="min(60vh, 520px)" className="tf-skel-block" />}
            {variant === 'detail' && (
                <>
                    <div className="row g-3 mb-4"><SkeletonStats /></div>
                    <div className="row g-3">
                        <div className="col-lg-7"><SkeletonCard lines={5} /></div>
                        <div className="col-lg-5"><SkeletonCard lines={5} /></div>
                    </div>
                </>
            )}
        </div>
    );
}
