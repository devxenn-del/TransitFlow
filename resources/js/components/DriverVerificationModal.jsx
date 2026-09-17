import { useEffect, useRef, useState } from 'react';

import Modal from './Modal.jsx';

const SCANNER_ELEMENT_ID = 'driver-qr-scanner';

/**
 * A conductor is not paired with one fixed driver — any driver's code (or
 * its QR, generated on the Drivers admin page) verifies them for this
 * sign-in. Shown after email+password when the backend's /auth/login
 * response says a driver_code is required (see Login.jsx and
 * AuthController::verifyDriverCode()). Stays open on a rejected code so
 * the conductor can retry without re-entering their password.
 */
export default function DriverVerificationModal({ open, busy, error, onCancel, onSubmit }) {
    const [code, setCode] = useState('');
    const [scanning, setScanning] = useState(false);
    const [scanError, setScanError] = useState(null);
    const scannerRef = useRef(null);

    useEffect(() => {
        if (!open) {
            setCode('');
            setScanning(false);
            setScanError(null);
        }
    }, [open]);

    useEffect(() => {
        if (!scanning) return undefined;

        let cancelled = false;
        let instance = null;

        import('html5-qrcode').then(({ Html5Qrcode }) => {
            if (cancelled) return;
            instance = new Html5Qrcode(SCANNER_ELEMENT_ID);
            scannerRef.current = instance;
            instance
                .start(
                    { facingMode: 'environment' },
                    { fps: 10, qrbox: 220 },
                    (decodedText) => {
                        setCode(decodedText.trim().toUpperCase());
                        setScanning(false);
                    },
                    () => {},
                )
                .catch(() => setScanError('Could not access the camera — enter the code manually instead.'));
        });

        return () => {
            cancelled = true;
            if (instance) {
                instance.stop().then(() => instance.clear()).catch(() => {});
            }
            scannerRef.current = null;
        };
    }, [scanning]);

    const submit = (e) => {
        e.preventDefault();
        if (!code.trim()) return;
        onSubmit(code.trim());
    };

    return (
        <Modal
            open={open}
            title="Verify Driver"
            onClose={onCancel}
            footer={(
                <>
                    <button type="button" className="btn btn-light" onClick={onCancel}>Cancel</button>
                    <button type="submit" form="driver-verification-form" className="btn btn-primary" disabled={busy || !code.trim()}>
                        {busy && <span className="spinner-border spinner-border-sm me-2" />}Sign In
                    </button>
                </>
            )}
        >
            <form id="driver-verification-form" onSubmit={submit} className="vstack gap-3">
                <p className="small text-body-secondary mb-0">
                    Scan the driver's QR code, or enter their driver code below, to continue.
                </p>

                {scanning ? (
                    <div>
                        <div id={SCANNER_ELEMENT_ID} style={{ width: '100%' }} />
                        <button type="button" className="btn btn-sm btn-outline-secondary mt-2" onClick={() => setScanning(false)}>
                            Cancel scan
                        </button>
                    </div>
                ) : (
                    <button type="button" className="btn btn-outline-secondary" onClick={() => { setScanError(null); setScanning(true); }}>
                        <i className="bi bi-qr-code-scan me-2" />Scan QR Code
                    </button>
                )}

                {scanError && <div className="small text-danger mb-0">{scanError}</div>}

                <div>
                    <label className="form-label small text-body-secondary">or enter it manually</label>
                    <input
                        className="form-control"
                        placeholder="Driver Code"
                        value={code}
                        onChange={(e) => setCode(e.target.value.toUpperCase())}
                        autoFocus
                    />
                </div>

                {error && <div className="small text-danger mb-0">{error}</div>}
            </form>
        </Modal>
    );
}
