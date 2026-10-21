import QRCode from 'qrcode';
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

import transitFlowLogo from '../../images/transitflow-logo.png';
import { SkeletonText } from '../components/Skeleton.jsx';
import { platformThermalPrinters } from '../lib/api.js';

const FILTER_KEYS = ['q', 'status', 'company_id', 'bus_id', 'assignment'];
const NOT_ASSIGNED = 'Not Assigned';

/** Smallest text allowed on a label, in pt. */
const MIN_FONT_PT = 5.5;

/**
 * Shrink each `[data-fit]` line inside `root` until it fits its column
 * (never below MIN_FONT_PT), so a long Thermal ID or company name stays on
 * one line instead of wrapping out of the label.
 */
function useFitText(root, deps) {
    useLayoutEffect(() => {
        root.current?.querySelectorAll('[data-fit]').forEach((el) => {
            let size = Number(el.dataset.fit);
            el.style.fontSize = `${size}pt`;
            while (el.scrollWidth > el.clientWidth && size > MIN_FONT_PT) {
                size = Math.max(MIN_FONT_PT, size - 0.25);
                el.style.fontSize = `${size}pt`;
            }
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, deps);
}

/**
 * One 58 × 40 mm asset label: the MAC address as a QR code (what the
 * conductor app scans to pair with the printer) beside its values — no
 * field names — and the TransitFlow footer band. Pure black on white for
 * direct-thermal paper.
 */
function PrinterLabel({ printer, qrSvg }) {
    const bus = printer.bus ? `${printer.bus.bus_number}${printer.bus.plate_number ? ` · ${printer.bus.plate_number}` : ''}` : NOT_ASSIGNED;

    return (
        <section className="tf-label" aria-label={`Label for ${printer.thermal_id}`}>
            <div className="tf-label__frame">
                <div className="tf-label__body">
                    {qrSvg ? (
                        // eslint-disable-next-line react/no-danger -- SVG generated locally by the qrcode library from a validated MAC address
                        <div className="tf-label__qr" role="img" aria-label={`QR code: ${printer.mac_address}`} dangerouslySetInnerHTML={{ __html: qrSvg }} />
                    ) : (
                        <div className="tf-label__qr tf-label__qr--missing">
                            <i className="bi bi-exclamation-octagon" aria-hidden="true" />
                            <span>MAC NOT<br />REGISTERED</span>
                        </div>
                    )}
                    <div className="tf-label__divider" aria-hidden="true" />
                    <div className="tf-label__data">
                        <div className="tf-label__tid" data-fit="7.5">{printer.thermal_id}</div>
                        <div className="tf-label__mono" data-fit="6.5">{printer.device_id}</div>
                        <div className="tf-label__mono" data-fit="6.5">{printer.mac_address ?? 'MAC NOT REGISTERED'}</div>
                        <div className="tf-label__company" data-fit="6.5">{printer.company?.code ?? NOT_ASSIGNED}</div>
                        <div className="tf-label__bus" data-fit="6.5">{bus}</div>
                    </div>
                </div>
                <footer className="tf-label__footer">
                    <span className="tf-label__mark"><img src={transitFlowLogo} alt="" /></span>
                    <span>TRANSITFLOW IT SOLUTIONS</span>
                </footer>
            </div>
        </section>
    );
}

/**
 * Print page for thermal-printer asset labels (Thermal Printer Management →
 * Print labels). Opened in its own tab by openPrinterLabels() with either
 * `ids=1,2,3` or `all=1` + the inventory filters. Each label is its own
 * 58 × 40 mm page (`@page tf-label`), so the label printer feeds one label
 * per printer. Prints automatically once every QR code has rendered —
 * unless some printers have no MAC address, which it asks about first.
 */
export default function PrinterLabels() {
    const [sp] = useSearchParams();
    const [printers, setPrinters] = useState(undefined); // undefined = loading
    const [qrCodes, setQrCodes] = useState(null);
    const [error, setError] = useState('');
    const sheet = useRef(null);

    useEffect(() => {
        const ids = (sp.get('ids') ?? '').split(',').filter((id) => /^\d+$/.test(id));
        const params = ids.length
            ? { ids }
            : { all: 1, ...Object.fromEntries(FILTER_KEYS.map((k) => [k, sp.get(k) || undefined])) };

        if (!ids.length && sp.get('all') !== '1') {
            setError('No printers were selected.');
            setPrinters(null);
            return;
        }

        platformThermalPrinters.labels(params)
            .then(setPrinters)
            .catch((e) => {
                const errors = e?.response?.data?.errors;
                setError((errors && Object.values(errors)[0]?.[0]) || e?.response?.data?.message || 'Could not load the labels.');
                setPrinters(null);
            });
    }, [sp]);

    // One SVG per printer that has a MAC address — the QR always holds the MAC, nothing else.
    useEffect(() => {
        if (!printers) return;
        let active = true;
        Promise.all(printers.map((p) => (p.mac_address
            ? QRCode.toString(p.mac_address, { type: 'svg', margin: 1, errorCorrectionLevel: 'M', color: { dark: '#000000', light: '#ffffff' } })
            : Promise.resolve(null))))
            .then((svgs) => active && setQrCodes(Object.fromEntries(printers.map((p, i) => [p.id, svgs[i]]))))
            .catch(() => active && setError('Could not generate the QR codes.'));
        return () => { active = false; };
    }, [printers]);

    useFitText(sheet, [qrCodes]);

    const missingMac = useMemo(() => (printers ?? []).filter((p) => !p.mac_address), [printers]);
    const ready = !!printers && !!qrCodes;

    // Auto-print once everything is drawn and the fonts have loaded — but
    // not when some labels would carry no QR; the toolbar asks first.
    useEffect(() => {
        if (!ready || missingMac.length) return undefined;
        let cancelled = false;
        const t = setTimeout(() => document.fonts.ready.then(() => !cancelled && window.print()), 450);
        return () => { cancelled = true; clearTimeout(t); };
    }, [ready, missingMac.length]);

    if (printers === undefined || (printers && !qrCodes && !error)) {
        return (
            <div className="tf-label-page">
                <div className="tf-label-toolbar"><SkeletonText lines={2} /></div>
            </div>
        );
    }

    if (!printers || !qrCodes) {
        return (
            <div className="tf-label-page">
                <div className="tf-label-toolbar text-center">
                    <p className="mb-3"><i className="bi bi-exclamation-circle me-1" />{error}</p>
                    <button type="button" className="btn btn-sm btn-light" onClick={() => window.close()}>Close</button>
                </div>
            </div>
        );
    }

    return (
        <div className="tf-label-page">
            <div className="tf-label-toolbar tf-no-print">
                <div className="d-flex flex-wrap align-items-center gap-2">
                    <div className="me-auto">
                        <div className="fw-semibold"><i className="bi bi-qr-code me-2" />{printers.length} printer {printers.length === 1 ? 'label' : 'labels'}</div>
                        <div className="small text-body-secondary">58 × 40 mm thermal labels · one label per printer. In the print dialog, choose your label printer and set margins to None.</div>
                    </div>
                    <button type="button" className="btn btn-primary" onClick={() => window.print()}>
                        <i className="bi bi-printer me-1" /> {missingMac.length ? 'Print anyway' : 'Print'}
                    </button>
                    <button type="button" className="btn btn-light" onClick={() => window.close()}>Close</button>
                </div>
                {missingMac.length > 0 && (
                    <div className="alert alert-warning small mt-3 mb-0">
                        <i className="bi bi-exclamation-triangle me-1" />
                        <strong>{missingMac.length} {missingMac.length === 1 ? 'printer has' : 'printers have'} no MAC address</strong>, so {missingMac.length === 1 ? 'its label' : 'their labels'} can’t carry the QR code the app scans to connect:{' '}
                        {missingMac.slice(0, 10).map((p) => p.thermal_id).join(', ')}{missingMac.length > 10 ? `, and ${missingMac.length - 10} more` : ''}.
                        {' '}Add the MAC address with <em>Edit printer</em> and print again, or print these labels without a QR code.
                    </div>
                )}
            </div>

            <div className="tf-label-sheet" ref={sheet}>
                {printers.map((p) => <PrinterLabel key={p.id} printer={p} qrSvg={qrCodes[p.id]} />)}
            </div>
        </div>
    );
}
