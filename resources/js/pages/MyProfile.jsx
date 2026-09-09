import { useEffect, useState } from 'react';

import { useAuth } from '../auth/AuthContext.jsx';
import PageHeader from '../components/PageHeader.jsx';
import { auth as authApi } from '../lib/api.js';
import { notifyError, notifySuccess } from '../lib/ui.js';

/**
 * Self-service edit of the signed-in user's own profile — BITS
 * `admin/profile.php`. Every account (any role) can reach this; it is not
 * gated behind a permission key.
 */
export default function MyProfile() {
    const { user, refreshUser } = useAuth();
    const [form, setForm] = useState(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!user) return;
        setForm({
            name: user.name ?? '',
            first_name: user.first_name ?? '',
            middle_name: user.middle_name ?? '',
            last_name: user.last_name ?? '',
            phone: user.phone ?? '',
            address: user.address ?? '',
            sex: user.sex ?? '',
        });
    }, [user]);

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            await authApi.updateProfile(form);
            await refreshUser();
            notifySuccess('Profile saved.');
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    if (!form) {
        return <div className="d-flex justify-content-center py-5"><span className="spinner-border text-primary" /></div>;
    }

    return (
        <>
            <PageHeader
                title="My Profile"
                subtitle={<>Employee ID <code>{user.employee_id ?? '—'}</code></>}
            />

            <div className="row">
                <div className="col-xl-8">
                    <form onSubmit={save} className="card border-0 shadow-sm">
                        <div className="card-body vstack gap-3">
                            <div>
                                <label className="form-label">Display name</label>
                                <input className="form-control" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                            </div>
                            <div className="row g-2">
                                <div className="col-md-4">
                                    <label className="form-label">First name</label>
                                    <input className="form-control" value={form.first_name} onChange={(e) => setForm({ ...form, first_name: e.target.value })} />
                                </div>
                                <div className="col-md-4">
                                    <label className="form-label">Middle name</label>
                                    <input className="form-control" value={form.middle_name} onChange={(e) => setForm({ ...form, middle_name: e.target.value })} />
                                </div>
                                <div className="col-md-4">
                                    <label className="form-label">Last name</label>
                                    <input className="form-control" value={form.last_name} onChange={(e) => setForm({ ...form, last_name: e.target.value })} />
                                </div>
                            </div>
                            <div className="row g-2">
                                <div className="col-md-6">
                                    <label className="form-label">Phone</label>
                                    <input className="form-control" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
                                </div>
                                <div className="col-md-6">
                                    <label className="form-label">Sex</label>
                                    <select className="form-select" value={form.sex} onChange={(e) => setForm({ ...form, sex: e.target.value })}>
                                        <option value="">—</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label className="form-label">Address</label>
                                <input className="form-control" value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} />
                            </div>
                            <div>
                                <label className="form-label">Email</label>
                                <input className="form-control" value={user.email} disabled />
                                <div className="form-text">Contact your administrator to change your sign-in email.</div>
                            </div>
                        </div>
                        <div className="card-footer bg-white text-end">
                            <button className="btn btn-primary" disabled={saving}>
                                {saving && <span className="spinner-border spinner-border-sm me-2" />}Save changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </>
    );
}
