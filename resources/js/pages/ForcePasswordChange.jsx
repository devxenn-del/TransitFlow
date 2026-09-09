import { useState } from 'react';

import { useAuth } from '../auth/AuthContext.jsx';
import { auth as authApi } from '../lib/api.js';
import { errorMessage, notifySuccess } from '../lib/ui.js';

/**
 * Shown full-screen when the signed-in user has `must_change_password`
 * (a brand-new company admin). Nothing else in the app is reachable until
 * they set their own password.
 */
export default function ForcePasswordChange() {
    const { user, refreshUser, logout } = useAuth();
    const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const submit = async (e) => {
        e.preventDefault();
        setError(null);
        if (form.password !== form.password_confirmation) {
            setError('The new passwords do not match.');
            return;
        }
        setBusy(true);
        try {
            await authApi.changePassword(form);
            await refreshUser();
            notifySuccess('Password updated. Welcome aboard.');
        } catch (err) {
            setError(errorMessage(err, 'Could not update your password.'));
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="tf-login">
            <div className="tf-login-form-side" style={{ minHeight: '100vh' }}>
                <div className="tf-login-card">
                    <h1 className="h4 mb-1">Set your password</h1>
                    <p className="text-body-secondary small mb-4">
                        Welcome, {user?.name}. Your company account was created with a temporary password —
                        choose your own to continue.
                    </p>

                    {error && <div className="alert alert-danger py-2 small">{error}</div>}

                    <form onSubmit={submit} className="vstack gap-3">
                        <div>
                            <label className="form-label">Temporary password</label>
                            <input type="password" className="form-control" required autoFocus
                                value={form.current_password}
                                onChange={(e) => setForm({ ...form, current_password: e.target.value })} />
                        </div>
                        <div>
                            <label className="form-label">New password</label>
                            <input type="password" className="form-control" required minLength={8}
                                value={form.password}
                                onChange={(e) => setForm({ ...form, password: e.target.value })} />
                        </div>
                        <div>
                            <label className="form-label">Confirm new password</label>
                            <input type="password" className="form-control" required minLength={8}
                                value={form.password_confirmation}
                                onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })} />
                        </div>
                        <button className="btn btn-primary w-100" disabled={busy}>
                            {busy && <span className="spinner-border spinner-border-sm me-2" />}
                            Save and continue
                        </button>
                    </form>

                    <button className="btn btn-link btn-sm w-100 mt-2 text-body-secondary" onClick={logout}>
                        Sign out
                    </button>
                </div>
            </div>
        </div>
    );
}
