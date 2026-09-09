import { useEffect, useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import StatusBadge from '../../components/StatusBadge.jsx';
import { companyProfile } from '../../lib/api.js';
import { notifyError, notifySuccess } from '../../lib/ui.js';

export default function CompanyProfile() {
    const { can } = useAuth();
    const editable = can('company.profile.edit');
    const [company, setCompany] = useState(null);
    const [form, setForm] = useState(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        companyProfile
            .get()
            .then((c) => {
                setCompany(c);
                setForm({
                    name: c.name, email: c.email ?? '', phone: c.phone ?? '',
                    address_line: c.address?.line ?? '', address_barangay: c.address?.barangay ?? '',
                    address_city: c.address?.city ?? '', address_province: c.address?.province ?? '',
                });
            })
            .catch((err) => notifyError(err, 'Could not load the company profile.'));
    }, []);

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const updated = await companyProfile.update(form);
            setCompany(updated);
            notifySuccess('Company profile saved.');
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
                title="Company Profile"
                subtitle={<>Code <code>{company.code}</code> · <StatusBadge value={company.status} /></>}
            />

            <div className="row">
                <div className="col-xl-8">
                    <form onSubmit={save} className="card border-0 shadow-sm">
                        <div className="card-body vstack gap-3">
                            <div>
                                <label className="form-label">Company name</label>
                                <input className="form-control" disabled={!editable} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                            </div>
                            <div className="row g-2">
                                <div className="col-md-6">
                                    <label className="form-label">Email</label>
                                    <input type="email" className="form-control" disabled={!editable} value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
                                </div>
                                <div className="col-md-6">
                                    <label className="form-label">Phone</label>
                                    <input className="form-control" disabled={!editable} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
                                </div>
                            </div>
                            <div>
                                <label className="form-label">Address line</label>
                                <input className="form-control" disabled={!editable} value={form.address_line} onChange={(e) => setForm({ ...form, address_line: e.target.value })} />
                            </div>
                            <div className="row g-2">
                                <div className="col-md-4">
                                    <label className="form-label">Barangay</label>
                                    <input className="form-control" disabled={!editable} value={form.address_barangay} onChange={(e) => setForm({ ...form, address_barangay: e.target.value })} />
                                </div>
                                <div className="col-md-4">
                                    <label className="form-label">City</label>
                                    <input className="form-control" disabled={!editable} value={form.address_city} onChange={(e) => setForm({ ...form, address_city: e.target.value })} />
                                </div>
                                <div className="col-md-4">
                                    <label className="form-label">Province</label>
                                    <input className="form-control" disabled={!editable} value={form.address_province} onChange={(e) => setForm({ ...form, address_province: e.target.value })} />
                                </div>
                            </div>
                        </div>
                        {editable && (
                            <div className="card-footer bg-white text-end">
                                <button className="btn btn-primary" disabled={saving}>
                                    {saving && <span className="spinner-border spinner-border-sm me-2" />}Save changes
                                </button>
                            </div>
                        )}
                    </form>
                </div>
            </div>
        </>
    );
}
