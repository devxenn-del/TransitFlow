/**
 * Bootstrap table with a consistent loading / empty state.
 *
 * columns: [{ key, header, className?, render?(row) }]
 */
export default function DataTable({ columns, rows, loading, empty = 'Nothing here yet.', rowKey = (r) => r.id }) {
    return (
        <div className="card">
            <div className="table-responsive">
                <table className="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            {columns.map((c) => (
                                <th key={c.key} className={c.className}>
                                    {c.header}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {loading && (
                            <tr>
                                <td colSpan={columns.length} className="tf-empty">
                                    <span className="spinner-border spinner-border-sm" />
                                </td>
                            </tr>
                        )}
                        {!loading && rows.length === 0 && (
                            <tr>
                                <td colSpan={columns.length} className="tf-empty">
                                    {empty}
                                </td>
                            </tr>
                        )}
                        {!loading &&
                            rows.map((row) => (
                                <tr key={rowKey(row)}>
                                    {columns.map((c) => (
                                        <td key={c.key} className={c.className}>
                                            {c.render ? c.render(row) : row[c.key]}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
