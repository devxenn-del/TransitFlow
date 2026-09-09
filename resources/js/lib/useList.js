import { useCallback, useEffect, useState } from 'react';

import { notifyError } from './ui.js';

/**
 * Loads a paginated Laravel resource collection ({ data, meta }).
 * Returns rows, meta, loading, page controls and a reload().
 */
export function useList(fetcher, { deps = [], perPage = 20 } = {}) {
    const [rows, setRows] = useState([]);
    const [meta, setMeta] = useState(null);
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [reloadKey, setReloadKey] = useState(0);

    const reload = useCallback(() => setReloadKey((k) => k + 1), []);

    useEffect(() => {
        let active = true;
        setLoading(true);
        Promise.resolve(fetcher({ page, per_page: perPage }))
            .then((res) => {
                if (!active) return;
                setRows(res.data ?? []);
                setMeta(res.meta ?? null);
            })
            .catch((err) => active && notifyError(err, 'Could not load the list.'))
            .finally(() => active && setLoading(false));
        return () => {
            active = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [page, perPage, reloadKey, ...deps]);

    return { rows, meta, loading, page, setPage, reload };
}
