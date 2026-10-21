import { useEffect, useRef, useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import StatusBadge from '../../components/StatusBadge.jsx';
import { companyProfile } from '../../lib/api.js';
import { useCompanyScope } from '../../lib/companyScope.jsx';
import { notifyError, notifySuccess } from '../../lib/ui.js';
import { SkeletonPage } from '../../components/Skeleton.jsx';

// Shows through a transparent logo, so it's clear the transparency was kept.
const CHECKERBOARD = {
    backgroundColor: '#fff',
    backgroundImage: 'conic-gradient(#e5e7eb 25%, transparent 0 50%, #e5e7eb 0 75%, transparent 0)',
    backgroundSize: '16px 16px',
};

function LogoField({ url, editable, busy, onUpload, onClear }) {
    const input = useRef(null);
    return (
        <div className="d-flex gap-3 align-items-center">
            <div
                className={`border rounded-circle d-flex align-items-center justify-content-center flex-shrink-0${url ? '' : ' bg-body-tertiary'}`}
                style={{ width: 96, height: 96, overflow: 'hidden', ...(url ? CHECKERBOARD : {}) }}
            >
                {url
                    ? <img src={url} alt="Company logo" style={{ width: '100%', height: '100%', objectFit: 'contain' }} />
                    : <i className="bi bi-buildings text-muted fs-3" />}
            </div>
            <div>
                <label className="form-label mb-1">Company logo</label>
                <div className="text-muted small mb-2">PNG / JPG / WEBP. Resized to fit a 512×512 square on upload. Shown in the top bar and sidebar.</div>
                {editable && (
                    <div className="d-flex gap-2">
                        <input
                            ref={input}
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            className="d-none"
                            onChange={(e) => {
                                const f = e.target.files?.[0];
                                if (f) onUpload(f);
                                e.target.value = '';
                            }}
                        />
                        <button type="button" className="btn btn-sm btn-outline-secondary" disabled={busy} onClick={() => input.current?.click()}>
                            {busy ? <span className="spinner-border spinner-border-sm me-1" /> : <i className="bi bi-upload me-1" />} Upload
                        </button>
                        {url && (
                            <button type="button" className="btn btn-sm btn-outline-danger" disabled={busy} onClick={onClear}>
                                <i className="bi bi-trash me-1" /> Remove
                            </button>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}

export default function CompanyProfile() {
    const { can, user, refreshUser } = useAuth();
    const editable = can('company.profile.edit');
    const scope = useCompanyScope();
    const [company, setCompany] = useState(null);
    const [form, setForm] = useState(null);
    const [saving, setSaving] = useState(false);
    const [logoBusy, setLogoBusy] = useState(false);

    useEffect(() => {
        companyProfile
            .get()
            .then((c) => {
                setCompany(c);
                setForm({
                    name: c.name, phone: c.phone ?? '',
                    address_line: c.address?.line ?? '', address_barangay: c.address?.barangay ?? '',
                    address_city: c.address?.city ?? '', address_province: c.address?.province ?? '',
                });
            })
            .catch((err) => notifyError(err, 'Could not load the company profile.'));
    }, []);

    // Refresh whatever shows the company's name/logo: the signed-in user's own
    // company (top-bar brand mark), or the workspace a Super Admin scoped into.
    const refreshBranding = () => {
        scope?.reload();
        if (user?.company) {
            refreshUser().catch(() => {});
        }
    };

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const updated = await companyProfile.update(form);
            setCompany(updated);
            refreshBranding();
            notifySuccess('Company profile saved.');
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const changeLogo = async (file) => {
        setLogoBusy(true);
        try {
            const settings = file ? await companyProfile.uploadLogo(file) : await companyProfile.deleteLogo();
            setCompany((c) => ({ ...c, settings }));
            refreshBranding();
            notifySuccess(file ? 'Company logo updated.' : 'Company logo removed.');
        } catch (err) {
            notifyError(err);
        } finally {
            setLogoBusy(false);
        }
    };

    if (!form) {
        return <SkeletonPage variant="form" />;
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
                            <LogoField
                                url={company.settings?.logo_url}
                                editable={editable}
                                busy={logoBusy}
                                onUpload={changeLogo}
                                onClear={() => changeLogo(null)}
                            />
                            <div>
                                <label className="form-label">Company name</label>
                                <input className="form-control" disabled={!editable} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                            </div>
                            <div className="row g-2">
                                <div className="col-md-6">
                                    <label className="form-label">Email</label>
                                    <input type="email" className="form-control" disabled readOnly value={company.email ?? ''} />
                                    <div className="form-text">The company email can't be changed here. Contact the platform administrator.</div>
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
