import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

import { receipts } from '../lib/api.js';

/**
 * Full-screen thermal-receipt print page — BITS `receipt/conductor/*.php`.
 * Reached in a new tab via `openReceipt()` (lib/receipt.js). Renders the
 * DTO at `width_mm` and auto-prints once, like the legacy slips.
 */
export default function ReceiptView() {
    const [sp] = useSearchParams();
    const src = sp.get('src');
    const kind = sp.get('kind');
    const id = sp.get('id');
    const qty = sp.get('qty');
    const date = sp.get('date');

    const [doc, setDoc] = useState(undefined); // undefined = loading
    const [error, setError] = useState('');

    useEffect(() => {
        let request;
        if (src === 'conductor' && kind && id) request = receipts.conductorTrip(id, kind);
        else if (src === 'company' && kind && id) request = receipts.companyTrip(id, kind);
        else if (src === 'monitor' && kind && id) request = receipts.monitorTrip(id, kind);
        else if (src === 'ticket' && id) request = receipts.ticket(id, Number(qty) || 1);
        else if (src === 'dispatch' && id) request = receipts.dispatch(id);
        else if (src === 'shift') request = receipts.shiftSummary(date);
        else {
            setError('This receipt link is missing something.');
            setDoc(null);
            return;
        }

        request
            .then(setDoc)
            .catch((e) => {
                setError(e?.response?.data?.message || 'Could not load this receipt.');
                setDoc(null);
            });
    }, [src, kind, id, qty, date]);

    // Auto-print once the slip is on screen (the browser dialog still needs
    // a human — no browser prints silently).
    useEffect(() => {
        if (!doc) return undefined;
        const t = setTimeout(() => window.print(), 450);
        return () => clearTimeout(t);
    }, [doc]);

    const printedAt = useMemo(
        () => (doc?.printed_at ? new Date(doc.printed_at).toLocaleString() : ''),
        [doc],
    );

    if (doc === undefined) {
        return (
            <div className="tf-receipt-page">
                <div className="spinner-border text-secondary" role="status" />
            </div>
        );
    }

    if (!doc) {
        return (
            <div className="tf-receipt-page">
                <div className="tf-receipt" style={{ textAlign: 'center' }}>
                    <p className="mb-3">{error}</p>
                    <button type="button" className="btn btn-sm btn-light" onClick={() => window.close()}>Close</button>
                </div>
            </div>
        );
    }

    return (
        <div className="tf-receipt-page">
            <div className="tf-receipt-toolbar tf-no-print">
                <button type="button" className="btn btn-sm btn-primary" onClick={() => window.print()}>
                    <i className="bi bi-printer me-1" /> Print
                </button>
                <button type="button" className="btn btn-sm btn-light" onClick={() => window.close()}>Close</button>
            </div>

            <div className="tf-receipt" style={{ '--tf-receipt-w': `${doc.width_mm || 58}mm` }}>
                {doc.org?.logo_path && (
                    <img src={`/storage/${doc.org.logo_path}`} alt="" className="tf-receipt__logo" />
                )}
                <div className="tf-receipt__title">{doc.title}</div>
                {doc.subtitle && <div className="tf-receipt__title tf-receipt__subtitle">{doc.subtitle}</div>}
                <div className="tf-receipt__org">{doc.org?.name}</div>
                {doc.org?.registration_number && (
                    <div className="tf-receipt__org tf-receipt__muted">Reg. {doc.org.registration_number}</div>
                )}
                {doc.org?.otc_accreditation_number && (
                    <div className="tf-receipt__org tf-receipt__muted">OTC {doc.org.otc_accreditation_number}</div>
                )}
                {doc.reference && <div className="tf-receipt__ref">{doc.reference}</div>}

                {(doc.sections || []).map((section, si) => (
                    <div className="tf-receipt__section" key={si}>
                        {section.heading && <div className="tf-receipt__heading">{section.heading}</div>}
                        {(section.rows || []).map((row, ri) => (
                            row.divider ? (
                                <div className="tf-receipt__divider" key={ri} />
                            ) : (
                                <div
                                    key={ri}
                                    className={`tf-receipt__row${row.strong ? ' tf-receipt__row--strong' : ''}${row.total ? ' tf-receipt__row--total' : ''}`}
                                >
                                    <span className="tf-receipt__label">{row.label}</span>
                                    <span className="tf-receipt__value">{row.value}</span>
                                </div>
                            )
                        ))}
                    </div>
                ))}

                {doc.note && <div className="tf-receipt__note">{doc.note}</div>}
                <div className="tf-receipt__note tf-receipt__muted">Printed {printedAt}</div>
            </div>
        </div>
    );
}
