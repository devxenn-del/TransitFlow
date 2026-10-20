import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { notifyError } from './ui.js';

/** Rows per page on the operational lists (trips, fleet, fares, reports, …). */
export const LIST_PAGE_SIZE = 6;

/**
 * Loads a paginated Laravel resource collection ({ data, meta }).
 * Returns rows, meta, loading, page controls and a reload(). Changing a
 * filter (`deps`) starts again from page 1, and a page left empty (e.g. its
 * last row was deleted) steps back one.
 */
export function useList(fetcher, { deps = [], perPage = 20 } = {}) {
    const [rows, setRows] = useState([]);
    const [meta, setMeta] = useState(null);
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [reloadKey, setReloadKey] = useState(0);
    const depsKey = JSON.stringify(deps);
    const lastDepsKey = useRef(depsKey);

    const reload = useCallback(() => setReloadKey((k) => k + 1), []);

    useEffect(() => {
        if (lastDepsKey.current !== depsKey) {
            lastDepsKey.current = depsKey;
            if (page !== 1) {
                setPage(1);
                return undefined;
            }
        }

        let active = true;
        setLoading(true);
        Promise.resolve(fetcher({ page, per_page: perPage }))
            .then((res) => {
                if (!active) return;
                const data = res.data ?? [];
                if (data.length === 0 && page > 1) {
                    setPage(Math.max(1, res.meta?.last_page ?? page - 1));
                    return;
                }
                setRows(data);
                setMeta(res.meta ?? null);
            })
            .catch((err) => active && notifyError(err, 'Could not load the list.'))
            .finally(() => active && setLoading(false));
        return () => {
            active = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [page, perPage, reloadKey, depsKey]);

    return { rows, meta, loading, page, setPage, reload };
}

/**
 * Client-side paging for data that arrives in one response (reports).
 * Returns the current page's rows plus a Laravel-shaped `meta` so the same
 * <Pagination> component renders it. Goes back to page 1 when `rows` changes.
 */
export function usePagedRows(rows, perPage = LIST_PAGE_SIZE) {
    const [page, setPage] = useState(1);
    const total = rows?.length ?? 0;
    const lastPage = Math.max(1, Math.ceil(total / perPage));

    useEffect(() => setPage(1), [rows]);

    const current = Math.min(page, lastPage);
    const pageRows = useMemo(() => (rows ?? []).slice((current - 1) * perPage, current * perPage), [rows, current, perPage]);
    const meta = total === 0 ? null : {
        current_page: current,
        last_page: lastPage,
        per_page: perPage,
        total,
        from: (current - 1) * perPage + 1,
        to: Math.min(current * perPage, total),
    };

    return { pageRows, meta, page: current, setPage };
}
