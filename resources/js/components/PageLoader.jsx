import { useEffect, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';

import { getPendingRequests, subscribePendingRequests } from '../lib/axios.js';
import BrandLoader from './BrandLoader.jsx';

/** Navigations that settle faster than this never show the overlay. */
const SHOW_AFTER_MS = 120;
/** Once shown, stay up at least this long so it doesn't flicker. */
const MIN_VISIBLE_MS = 350;
/** Never block a page longer than this (e.g. a page that polls). */
const MAX_WAIT_MS = 8000;

/**
 * Covers the content area while a newly opened page loads its data: starts
 * on every pathname change and ends once no API request is in flight. A top
 * progress bar runs alongside it.
 */
export default function PageLoader() {
    const { pathname } = useLocation();
    const [busy, setBusy] = useState(false);
    const [visible, setVisible] = useState(false);
    const shownAt = useRef(0);

    useEffect(() => {
        let done = false;
        let showTimer;
        let hideTimer;
        let settleFrame;
        setBusy(true);

        const finish = () => {
            if (done) return;
            done = true;
            clearTimeout(showTimer);
            clearTimeout(maxTimer);
            unsubscribe();
            const remaining = shownAt.current ? Math.max(0, MIN_VISIBLE_MS - (Date.now() - shownAt.current)) : 0;
            hideTimer = setTimeout(() => {
                shownAt.current = 0;
                setVisible(false);
                setBusy(false);
            }, remaining);
        };

        // Let the new page mount and fire its requests before checking.
        const check = () => {
            cancelAnimationFrame(settleFrame);
            settleFrame = requestAnimationFrame(() => setTimeout(() => {
                if (getPendingRequests() === 0) finish();
            }, 30));
        };

        const unsubscribe = subscribePendingRequests((count) => count === 0 && check());
        showTimer = setTimeout(() => {
            if (done) return;
            shownAt.current = Date.now();
            setVisible(true);
        }, SHOW_AFTER_MS);
        const maxTimer = setTimeout(finish, MAX_WAIT_MS);
        check();

        return () => {
            done = true;
            unsubscribe();
            cancelAnimationFrame(settleFrame);
            clearTimeout(showTimer);
            clearTimeout(hideTimer);
            clearTimeout(maxTimer);
        };
    }, [pathname]);

    return (
        <>
            <div className={`tf-route-progress${busy ? ' is-busy' : ''}`} aria-hidden="true" />
            <div className={`tf-page-loader${visible ? ' show' : ''}`} aria-hidden={!visible}>
                {visible && <BrandLoader label="Loading page…" />}
            </div>
        </>
    );
}
