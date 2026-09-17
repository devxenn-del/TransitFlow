import { useState } from 'react';
import { Navigate, useLocation, useNavigate } from 'react-router-dom';

import { useAuth } from '../auth/AuthContext.jsx';
import DriverVerificationModal from '../components/DriverVerificationModal.jsx';
import { errorMessage } from '../lib/ui.js';

const FEATURES = [
    ['bi-diagram-3-fill', 'text-bg-primary', 'Multi-Company', 'Each company sees only its own fleet, routes, users and reports.'],
    ['bi-shield-lock-fill', 'text-bg-success', 'Roles & Permissions', 'Fine-grained, per-user access carried over from the BITS model.'],
    ['bi-bus-front-fill', 'text-bg-warning', 'Fleet & Fares', 'Terminals, routes and a fare matrix that drives every ticket.'],
    ['bi-graph-up-arrow', 'text-bg-info', 'Reports & Revenue', 'Trip income, remittance and operational reporting in one place.'],
];

const DEMO = [
    ['superadmin@transitflow.test', 'Super Admin'],
    ['admin@perjoda.test', 'Company Admin — Perjsoda'],
    ['staff@perjoda.test', 'Office staff — Perjoda'],
];

export default function Login() {
    const { login, isAuthenticated, loading } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const [form, setForm] = useState({ email: '', password: 'password', remember: true });
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    // Set only once /auth/login says this account needs a driver_code (a
    // conductor account) — the login form itself is just email/password for
    // everyone; see AuthController::verifyDriverCode() on the backend.
    const [driverPrompt, setDriverPrompt] = useState(null);
    const [driverError, setDriverError] = useState(null);

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

    return (
        <div className="tf-login">
            <div className="container-fluid">
                <div className="row min-vh-100">
                    {/* ---- Branding (hidden on small screens) ---- */}
                    <div className="col-lg-7 d-none d-lg-flex tf-login-branding">
                        <div className="tf-login-branding-inner">
                            <div className="tf-login-logo">
                                <div className="tf-login-logo-mark">
                                    <i className="bi bi-bus-front-fill" />
                                </div>
                                <div>
                                    <h1>TransitFlow</h1>
                                    <p>Smart Transport Management Platform</p>
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

                            <div className="tf-login-callout">
                                <span className="tf-login-callout-icon">
                                    <i className="bi bi-phone" />
                                </span>
                                <div className="flex-grow-1">
                                    <span className="badge">Coming soon</span>
                                    <h5>TransitFlow Mobile — conductor app</h5>
                                    <p>Conductors will run trips and issue tickets from their phone, on the same API.</p>
                                </div>
                                <i className="bi bi-arrow-up-right d-none d-xl-block" style={{ color: 'rgba(255,255,255,.55)' }} />
                            </div>

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
                                <div className="tf-login-mobile-mark d-lg-none">
                                    <i className="bi bi-bus-front-fill" />
                                </div>
                                <h1 className="h4 fw-bold mb-1">Welcome back</h1>
                                <p className="text-muted mb-0">Sign in to continue.</p>
                            </div>

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
                                            type="password"
                                            required
                                            placeholder="Your password"
                                            value={form.password}
                                            onChange={(e) => setForm({ ...form, password: e.target.value })}
                                        />
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

                            <div className="mt-3 pt-3 border-top">
                                <p className="small text-muted mb-2">
                                    Demo accounts — password <code>password</code>
                                </p>
                                <div className="d-flex flex-column gap-1">
                                    {DEMO.map(([email, label]) => (
                                        <button
                                            key={email}
                                            type="button"
                                            className="btn btn-sm btn-outline-secondary text-start"
                                            onClick={() => setForm((f) => ({ ...f, email, password: 'password' }))}
                                        >
                                            <span className="fw-semibold">{label}</span>
                                            <span className="text-muted"> · {email}</span>
                                        </button>
                                    ))}
                                </div>
                            </div>

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
