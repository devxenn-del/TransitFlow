import { useEffect, useState } from 'react';
import { Navigate, useLocation, useNavigate } from 'react-router-dom';

import { useAuth } from '../auth/AuthContext.jsx';
import DriverVerificationModal from '../components/DriverVerificationModal.jsx';
import transitFlowLogo from '../../images/transitflow-logo.png';
import { publicMobileApp } from '../lib/api.js';
import { errorMessage } from '../lib/ui.js';

const FEATURES = [
    ['bi-diagram-3-fill', 'tf-chip-navy', 'Multi-Company', 'Each company sees only its own fleet, routes, users and reports.'],
    ['bi-shield-lock-fill', 'tf-chip-green', 'Roles & Permissions', 'Fine-grained, per-user access carried over from the BITS model.'],
    ['bi-bus-front-fill', 'tf-chip-amber', 'Fleet & Fares', 'Terminals, routes and a fare matrix that drives every ticket.'],
    ['bi-graph-up-arrow', 'tf-chip-blue', 'Reports & Revenue', 'Trip income, remittance and operational reporting in one place.'],
];

export default function Login() {
    const { login, isAuthenticated, loading } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const [form, setForm] = useState({ email: '', password: '', remember: true });
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    // Set only once /auth/login says this account needs a driver_code (a
    // conductor account) — the login form itself is just email/password for
    // everyone; see AuthController::verifyDriverCode() on the backend.
    const [driverPrompt, setDriverPrompt] = useState(null);
    const [driverError, setDriverError] = useState(null);

    // One app, platform-wide — no company selection needed, just whether it's
    // been published yet (Api\Meta\ServerConfigController::mobileApp).
    const [appInfo, setAppInfo] = useState(null);

    useEffect(() => {
        publicMobileApp.get().then(setAppInfo).catch(() => {});
    }, []);

    if (!loading && isAuthenticated) {
        return <Navigate to={location.state?.from || '/'} replace />;
    }

    const attemptLogin = async (payload) => {
        try {
            await login(payload);
            navigate(location.state?.from || '/', { replace: true });
            return true;
        } catch (err) {
            if (err.response?.status === 422 && err.response?.data?.errors?.driver_code) {
                setDriverPrompt(payload);
                setDriverError(null);
                return false;
            }
            throw err;
        }
    };

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await attemptLogin(form);
        } catch (err) {
            setError(errorMessage(err, 'Unable to sign in.'));
        } finally {
            setBusy(false);
        }
    };

    const submitDriverCode = async (driverCode) => {
        setBusy(true);
        try {
            const ok = await attemptLogin({ ...driverPrompt, driver_code: driverCode });
            if (ok) setDriverPrompt(null);
        } catch (err) {
            setDriverError(errorMessage(err, 'Unable to verify that driver.'));
        } finally {
            setBusy(false);
        }
    };

    const mobileAppCallout = (
        <div className="tf-login-callout">
            <span className="tf-login-callout-icon">
                <i className="bi bi-phone" />
            </span>
            <div className="flex-grow-1">
                {appInfo?.download_url ? (
                    <>
                        <h5>TransitFlow Mobile</h5>
                        <p>A mobile solution that provides convenient access to TransitFlow features and services.</p>
                    </>
                ) : (
                    <>
                        <span className="badge">Coming soon</span>
                        <h5>TransitFlow Mobile</h5>
                        <p>A mobile solution that provides convenient access to TransitFlow features and services.</p>
                    </>
                )}
            </div>
            {appInfo?.download_url ? (
                <a href={appInfo.download_url} className="btn btn-sm btn-light fw-semibold text-nowrap">
                    <i className="bi bi-download me-1" />
                    Download{appInfo.latest_version ? ` v${appInfo.latest_version}` : ''}
                </a>
            ) : (
                <i className="bi bi-arrow-up-right d-none d-xl-block" style={{ color: 'rgba(255,255,255,.55)' }} />
            )}
        </div>
    );

    return (
        <div className="tf-login">
            <div className="container-fluid">
                <div className="row h-100">
                    {/* ---- Branding (hidden on small screens) ---- */}
                    <div className="col-lg-7 d-none d-lg-flex tf-login-branding">
                        <div className="tf-login-branding-inner">
                            <div className="tf-login-logo">
                                <img src={transitFlowLogo} alt="TransitFlow" className="tf-login-logo-mark" />
                                <div>
                                    <p className="tf-eyebrow mb-0">Smart Transport Management Platform</p>
                                </div>
                            </div>

                            <div className="tf-login-hero">
                                <h2>
                                    Run Every Route.
                                    <br />
                                    Track Every <span className="accent">Fare</span>.
                                </h2>
                                <p>
                                    One centralized platform for multiple transport companies — fleet, terminals,
                                    fares, trips, tickets, remittance and reports, with company-isolated data and
                                    role-based access for every account.
                                </p>
                            </div>

                            {mobileAppCallout}

                            <div className="tf-login-features">
                                {FEATURES.map(([icon, bg, title, desc]) => (
                                    <div className="tf-login-feature" key={title}>
                                        <span className={`tf-login-feature-icon ${bg}`}>
                                            <i className={`bi ${icon}`} />
                                        </span>
                                        <div>
                                            <h5>{title}</h5>
                                            <span>{desc}</span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* ---- Form ---- */}
                    <div className="col-lg-5 tf-login-form-side">
                        <div className="tf-login-card">
                            <div className="text-center mb-3">
                                <img src={transitFlowLogo} alt="TransitFlow" className="tf-login-mobile-mark d-lg-none" />
                                <h1 className="h4 fw-bold mb-1">Welcome back</h1>
                                <p className="text-muted mb-0">Sign in to continue.</p>
                            </div>

                            {/* Same callout as the desktop branding panel — that panel is
                                hidden below lg, so mobile visitors would otherwise never see it. */}
                            <div className="d-lg-none">{mobileAppCallout}</div>

                            {error && (
                                <div className="alert alert-danger d-flex align-items-center py-2 small mb-3" role="alert">
                                    <i className="bi bi-exclamation-circle-fill me-2" />
                                    {error}
                                </div>
                            )}

                            <form onSubmit={submit} className="vstack gap-3">
                                <div>
                                    <label className="form-label">Email address</label>
                                    <div className="tf-input-group">
                                        <span className="tf-input-icon"><i className="bi bi-envelope-fill" /></span>
                                        <input
                                            type="email"
                                            autoFocus
                                            required
                                            placeholder="you@company.com"
                                            value={form.email}
                                            onChange={(e) => setForm({ ...form, email: e.target.value })}
                                        />
                                    </div>
                                </div>
                                <div>
                                    <label className="form-label">Password</label>
                                    <div className="tf-input-group">
                                        <span className="tf-input-icon"><i className="bi bi-lock-fill" /></span>
                                        <input
                                            type={showPassword ? 'text' : 'password'}
                                            required
                                            placeholder="Your password"
                                            value={form.password}
                                            onChange={(e) => setForm({ ...form, password: e.target.value })}
                                        />
                                        <button
                                            type="button"
                                            className="tf-input-toggle"
                                            tabIndex={-1}
                                            aria-label={showPassword ? 'Hide password' : 'Show password'}
                                            onClick={() => setShowPassword((v) => !v)}
                                        >
                                            <i className={`bi ${showPassword ? 'bi-eye-slash-fill' : 'bi-eye-fill'}`} />
                                        </button>
                                    </div>
                                </div>
                                <div className="form-check">
                                    <input
                                        className="form-check-input"
                                        type="checkbox"
                                        id="remember"
                                        checked={form.remember}
                                        onChange={(e) => setForm({ ...form, remember: e.target.checked })}
                                    />
                                    <label className="form-check-label small" htmlFor="remember">
                                        Keep me signed in on this device
                                    </label>
                                </div>
                                <button type="submit" className="tf-login-btn w-100" disabled={busy}>
                                    {busy ? (
                                        <span className="spinner-border spinner-border-sm me-2" />
                                    ) : (
                                        <i className="bi bi-box-arrow-in-right me-2" />
                                    )}
                                    Sign in
                                </button>
                            </form>

                            <div className="tf-login-footer">
                                <p className="small text-muted mb-1">
                                    By continuing, you acknowledge the applicable Privacy Policy and Terms of Use.
                                </p>
                                <a href="/legal/privacy-policy" target="_blank" rel="noopener noreferrer" className="small">Privacy Policy</a>
                                {' · '}
                                <a href="/legal/terms-of-use" target="_blank" rel="noopener noreferrer" className="small">Terms of Use</a>
                                <div className="mt-2">© {new Date().getFullYear()} TransitFlow</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <DriverVerificationModal
                open={!!driverPrompt}
                busy={busy}
                error={driverError}
                onCancel={() => { setDriverPrompt(null); setDriverError(null); setBusy(false); }}
                onSubmit={submitDriverCode}
            />
        </div>
    );
}
