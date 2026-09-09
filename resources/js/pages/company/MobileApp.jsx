import { useEffect, useMemo, useRef, useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import { mobileApp } from '../../lib/api.js';
import { notifyError, notifySuccess } from '../../lib/ui.js';

const FIELDS = ['api_base_url', 'latest_version', 'latest_version_code', 'minimum_version', 'force_update', 'download_url', 'release_notes'];

function toForm(s) {
    return {
        api_base_url: s.api_base_url ?? '',
        latest_version: s.latest_version ?? '',
        latest_version_code: s.latest_version_code ?? 0,
        minimum_version: s.minimum_version ?? '',
        force_update: !!s.force_update,
        download_url: s.download_url ?? '',
        release_notes: s.release_notes ?? '',
    };
}

export default function MobileApp() {
    const { can, user } = useAuth();
    const editable = can('mobileapp.manage');
    const [settings, setSettings] = useState(null);
    const [form, setForm] = useState(null);
    const [saving, setSaving] = useState(false);
    const [apkBusy, setApkBusy] = useState(false);
    const apkInput = useRef(null);

    useEffect(() => {
        mobileApp.get()
            .then((s) => { setSettings(s); setForm(toForm(s)); })
            .catch((e) => notifyError(e, 'Could not load mobile-app settings.'));
    }, []);

    const configUrl = useMemo(() => {
        const code = user?.company?.code;
        return code ? `${window.location.origin}/api/meta/server-config?company=${code}` : null;
    }, [user]);

    const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const payload = { ...form };
            payload.latest_version_code = Number(payload.latest_version_code) || 0;
            FIELDS.forEach((k) => { if (payload[k] === '') payload[k] = null; });
            payload.force_update = !!form.force_update;
            const updated = await mobileApp.update(payload);
            setSettings(updated);
            setForm(toForm(updated));
            notifySuccess('Mobile-app settings saved.');
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const uploadApk = async (file) => {
        setApkBusy(true);
        try {
            const updated = await mobileApp.uploadApk(file);
            setSettings(updated);
            notifySuccess('APK uploaded.');
        } catch (err) {
            notifyError(err);
        } finally {
            setApkBusy(false);
        }
    };

    const removeApk = async () => {
        setApkBusy(true);
        try {
            const updated = await mobileApp.deleteApk();
            setSettings(updated);
            notifySuccess('APK removed.');
        } catch (err) {
            notifyError(err);
        } finally {
            setApkBusy(false);
        }
    };

    if (!form) {
        return <div className="d-flex justify-content-center py-5"><span className="spinner-border text-primary" /></div>;
    }

    return (
        <>
            <PageHeader
                eyebrow="Company"
                title="Mobile App"
                subtitle="Publish the conductor app version, host the APK, and control forced updates. The app reads this on launch."
            />

            <div className="row g-3">
                <div className="col-xl-7">
                    <form onSubmit={save} className="card border-0 shadow-sm">
                        <div className="card-body vstack gap-3">
                            <div className="row g-2">
                                <div className="col-sm-6">
                                    <label className="form-label">Latest version</label>
                                    <input className="form-control" placeholder="1.4.2" disabled={!editable}
                                        value={form.latest_version} onChange={(e) => set('latest_version', e.target.value)} />
                                </div>
                                <div className="col-sm-6">
                                    <label className="form-label">Latest version code</label>
                                    <input type="number" min="0" className="form-control" disabled={!editable}
                                        value={form.latest_version_code} onChange={(e) => set('latest_version_code', e.target.value)} />
                                </div>
                                <div className="col-sm-6">
                                    <label className="form-label">Minimum supported version</label>
                                    <input className="form-control" placeholder="1.0.0" disabled={!editable}
                                        value={form.minimum_version} onChange={(e) => set('minimum_version', e.target.value)} />
                                    <div className="form-text">Below this, the app is forced to update.</div>
                                </div>
                                <div className="col-sm-6 d-flex align-items-center">
                                    <div className="form-check mt-3">
                                        <input className="form-check-input" type="checkbox" id="force" disabled={!editable}
                                            checked={form.force_update} onChange={(e) => set('force_update', e.target.checked)} />
                                        <label className="form-check-label" htmlFor="force">Force update to latest</label>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label className="form-label">API base URL (override)</label>
                                <input className="form-control" placeholder={`${window.location.origin}/api`} disabled={!editable}
                                    value={form.api_base_url} onChange={(e) => set('api_base_url', e.target.value)} />
                                <div className="form-text">Leave blank to use this server. Set it to move the app to another host.</div>
                            </div>
                            <div>
                                <label className="form-label">Download URL</label>
                                <input className="form-control" placeholder="https://…/app.apk" disabled={!editable}
                                    value={form.download_url} onChange={(e) => set('download_url', e.target.value)} />
                                <div className="form-text">Used if set; otherwise the uploaded APK below is served.</div>
                            </div>
                            <div>
                                <label className="form-label">Release notes</label>
                                <textarea className="form-control" rows={3} disabled={!editable}
                                    value={form.release_notes} onChange={(e) => set('release_notes', e.target.value)} />
                            </div>
                        </div>
                        {editable && (
                            <div className="card-footer bg-white text-end">
                                <button className="btn btn-primary" disabled={saving}>
                                    {saving && <span className="spinner-border spinner-border-sm me-2" />}Publish
                                </button>
                            </div>
                        )}
                    </form>
                </div>

                <div className="col-xl-5">
                    <div className="card border-0 shadow-sm mb-3">
                        <div className="card-body">
                            <h2 className="h6 tf-eyebrow text-uppercase text-muted">APK file</h2>
                            {settings.apk_url ? (
                                <p className="small mb-2">
                                    <i className="bi bi-file-earmark-zip me-1" />
                                    <a href={settings.apk_url} target="_blank" rel="noreferrer">current APK</a>
                                    {settings.published_at && <span className="text-muted"> · {new Date(settings.published_at).toLocaleString()}</span>}
                                </p>
                            ) : (
                                <p className="small text-muted mb-2">No APK uploaded.</p>
                            )}
                            {editable && (
                                <div className="d-flex gap-2">
                                    <input ref={apkInput} type="file" accept=".apk" className="d-none"
                                        onChange={(e) => { const f = e.target.files?.[0]; if (f) uploadApk(f); e.target.value = ''; }} />
                                    <button type="button" className="btn btn-sm btn-outline-secondary" disabled={apkBusy}
                                        onClick={() => apkInput.current?.click()}>
                                        <i className="bi bi-upload me-1" />{apkBusy ? 'Working…' : 'Upload APK'}
                                    </button>
                                    {settings.apk_url && (
                                        <button type="button" className="btn btn-sm btn-outline-danger" disabled={apkBusy} onClick={removeApk}>
                                            <i className="bi bi-trash me-1" />Remove
                                        </button>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>

                    {configUrl && (
                        <div className="card border-0 shadow-sm">
                            <div className="card-body">
                                <h2 className="h6 tf-eyebrow text-uppercase text-muted">App server-config URL</h2>
                                <p className="small text-muted">Point the app here on first launch.</p>
                                <code className="d-block bg-body-tertiary p-2 rounded small text-break">{configUrl}</code>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
