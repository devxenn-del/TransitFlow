import { useEffect, useState } from 'react';

import { useAuth } from '../auth/AuthContext.jsx';
import { legalDocuments } from '../lib/api.js';
import { errorMessage } from '../lib/ui.js';

/**
 * Blocks the app behind a mandatory Privacy Policy / Terms of Use
 * acknowledgment — shown when `user.needs_legal_acceptance` is true (a new
 * user, or one who accepted an older version). Mirrors the mobile app's
 * first-run consent gate: unchecked by default, Continue disabled until
 * checked, links open the full documents in a new tab so this screen's
 * state isn't lost.
 */
export default function LegalConsentGate({ children }) {
    const { user, refreshUser } = useAuth();
    const [versions, setVersions] = useState(null);
    const [checked, setChecked] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const needsAcceptance = !!user?.needs_legal_acceptance;

    useEffect(() => {
        if (!needsAcceptance) return;
        legalDocuments.activePublic()
            .then((data) => setVersions({
                privacy_version: data.privacy_policy?.version ?? null,
                terms_version: data.terms_of_use?.version ?? null,
            }))
            .catch(() => setError('Could not load the current Privacy Policy and Terms of Use. Please try again.'));
    }, [needsAcceptance]);

    if (!needsAcceptance) {
        return children;
    }

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await legalDocuments.accept({ ...versions, platform: 'web' });
            await refreshUser();
        } catch (err) {
            setError(errorMessage(err, 'Could not record your acceptance. Please try again.'));
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="tf-login">
            <div className="d-flex align-items-center justify-content-center" style={{ minHeight: '100vh' }}>
                <div className="tf-login-card">
                    <h1 className="h4 fw-bold mb-1">Welcome to TransitFlow</h1>
                    <p className="text-body-secondary mb-4">
                        Before using TransitFlow, please review our Privacy Policy and Terms of Use.
                    </p>

                    {error && (
                        <div className="alert alert-danger py-2 small mb-3">{error}</div>
                    )}

                    <div className="vstack gap-2 mb-4">
                        <a href="/legal/privacy-policy" target="_blank" rel="noopener noreferrer" className="btn btn-outline-secondary text-start">
                            <i className="bi bi-file-earmark-text me-2" />Privacy Policy
                        </a>
                        <a href="/legal/terms-of-use" target="_blank" rel="noopener noreferrer" className="btn btn-outline-secondary text-start">
                            <i className="bi bi-file-earmark-text me-2" />Terms of Use
                        </a>
                    </div>

                    <form onSubmit={submit}>
                        <div className="form-check mb-4">
                            <input
                                className="form-check-input"
                                type="checkbox"
                                id="legal-agree"
                                checked={checked}
                                onChange={(e) => setChecked(e.target.checked)}
                            />
                            <label className="form-check-label small" htmlFor="legal-agree">
                                I have read and agree to the Privacy Policy and Terms of Use.
                            </label>
                        </div>
                        <button type="submit" className="btn btn-primary w-100" disabled={!checked || !versions || busy}>
                            {busy && <span className="spinner-border spinner-border-sm me-2" />}
                            Continue
                        </button>
                    </form>
                </div>
            </div>
        </div>
    );
}
