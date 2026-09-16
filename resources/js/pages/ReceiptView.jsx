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

    // The widest `form` row's label in a section, +1 for a hairline of
    // padding before the colon — mirrors ReceiptEscPosFormatter.formLabelWidth
    // so "Label : value" columns line up the same way here as on the printed
    // slip. 0 when the section has no form rows.
    const formLabelWidth = (rows) => {
        const max = (rows || []).reduce((m, row) => (row.form && row.label ? Math.max(m, row.label.length) : m), 0);
        return max === 0 ? 0 : max + 1;
    };

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
                <div className="tf-receipt__rule--heavy" />

                {doc.org?.logo_path && (
                    <img src={`/storage/${doc.org.logo_path}`} alt="" className="tf-receipt__logo" />
                )}
                {doc.org?.name && <div className="tf-receipt__org">{doc.org.name}</div>}
                {doc.org?.registration_number && (
                    <div className="tf-receipt__org tf-receipt__muted">Reg. {doc.org.registration_number}</div>
                )}
                {doc.org?.otc_accreditation_number && (
                    <div className="tf-receipt__org tf-receipt__muted">OTC {doc.org.otc_accreditation_number}</div>
                )}
                {doc.org?.name && <div className="tf-receipt__rule--heavy" />}

                <div className="tf-receipt__title">{doc.title}</div>
                {doc.subtitle && <div className="tf-receipt__title tf-receipt__subtitle">{doc.subtitle}</div>}
                {doc.reference && <div className="tf-receipt__ref">{doc.reference}</div>}

                {(doc.sections || []).map((section, si) => {
                    const labelWidth = formLabelWidth(section.rows);
                    return (
                        <div className="tf-receipt__section" key={si}>
                            <div className="tf-receipt__divider" />
                            {section.heading && <div className="tf-receipt__heading">{section.heading}</div>}
                            {(section.rows || []).map((row, ri) => {
                                if (row.divider) return <div className="tf-receipt__divider" key={ri} />;
                                const cls = `tf-receipt__row${row.form ? ' tf-receipt__row--form' : ''}${row.strong ? ' tf-receipt__row--strong' : ''}${row.total ? ' tf-receipt__row--total' : ''}`;
                                if (row.form) {
                                    return (
                                        <div className={cls} key={ri}>
                                            {(row.label || '').padEnd(labelWidth)}: {row.value}
                                        </div>
                                    );
                                }
                                return (
                                    <div className={cls} key={ri}>
                                        <span className="tf-receipt__label">{row.label}</span>
                                        <span className="tf-receipt__value">{row.value}</span>
                                    </div>
                                );
                            })}
                        </div>
                    );
                })}

                <div className="tf-receipt__rule--heavy" />
                {doc.note && (
                    <>
                        <div className="tf-receipt__note">{doc.note}</div>
                        <div className="tf-receipt__rule--heavy" />
                    </>
                )}
                <div className="tf-receipt__note tf-receipt__muted">Printed {printedAt}</div>
            </div>
        </div>
    );
}
