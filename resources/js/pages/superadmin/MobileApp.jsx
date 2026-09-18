import { useEffect, useRef, useState } from 'react';
import QRCode from 'qrcode';

import PageHeader from '../../components/PageHeader.jsx';
import { platformMobileApp } from '../../lib/api.js';
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

/**
 * One mobile app, platform-wide — every company's conductors run the same
 * build. A company never uploads its own; it only reads this published
 * state (see App\Http\Controllers\Api\Company\MobileAppController), and
 * downloads it directly from the login page once it's published — no
 * company code needed there either.
 *
 * Flow mirrors the legacy BITS admin/mobileapp.php: pick a new APK (or
 * leave it to only republish version info), fill in the version, and hit
 * Publish once — the file (if any) and the metadata go out together. The
 * download link/QR code never changes across releases (App\Models\
 * MobileAppSetting::current, stored under a fixed on-disk name).
 */
export default function MobileApp() {
    const [settings, setSettings] = useState(null);
    const [form, setForm] = useState(null);
    const [file, setFile] = useState(null);
    const [saving, setSaving] = useState(false);
    const [removing, setRemoving] = useState(false);
    const fileInput = useRef(null);
    const qrCanvas = useRef(null);

    useEffect(() => {
        platformMobileApp.get()
            .then((s) => { setSettings(s); setForm(toForm(s)); })
            .catch((e) => notifyError(e, 'Could not load mobile-app settings.'));
    }, []);

    useEffect(() => {
        if (settings?.apk_url && qrCanvas.current) {
            QRCode.toCanvas(qrCanvas.current, settings.apk_url, { width: 180, margin: 1 }).catch(() => {});
        }
    }, [settings?.apk_url]);

    const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));

    const publish = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            let updated = settings;
            if (file) {
                updated = await platformMobileApp.uploadApk(file);
            }

            const payload = { ...form };
            payload.latest_version_code = Number(payload.latest_version_code) || 0;
            FIELDS.forEach((k) => { if (payload[k] === '') payload[k] = null; });
            payload.force_update = !!form.force_update;
            updated = await platformMobileApp.update(payload);

            setSettings(updated);
            setForm(toForm(updated));
            setFile(null);
            if (fileInput.current) fileInput.current.value = '';
            notifySuccess('Update published — conductors will be prompted next time they open the app.');
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const removeApk = async () => {
        setRemoving(true);
        try {
            const updated = await platformMobileApp.deleteApk();
            setSettings(updated);
            notifySuccess('APK removed.');
        } catch (err) {
            notifyError(err);
        } finally {
            setRemoving(false);
        }
    };

    const copyLink = async () => {
        try {
            await navigator.clipboard.writeText(settings.apk_url);
            notifySuccess('Link copied.');
        } catch {
            notifyError(null, 'Could not copy — select and copy the link manually.');
        }
    };

    if (!form) {
        return <div className="d-flex justify-content-center py-5"><span className="spinner-border text-primary" /></div>;
    }

    return (
        <>
            <PageHeader
                eyebrow="Platform"
                title="Mobile App"
                subtitle="Publish the conductor app for every company. Downloadable directly from the login page once published."
            />

            <div className="row g-3">
                <div className="col-xl-7">
                    <div className="card border-0 shadow-sm">
                        <div className="card-header bg-white">
                            <h2 className="h6 mb-0 fw-semibold">Current version</h2>
                        </div>
                        <div className="card-body">
                            {settings.apk_url ? (
                                <>
                                    <div className="d-flex align-items-center gap-3 p-3 mb-3 rounded bg-light border">
                                        <i className="bi bi-android2" style={{ fontSize: '2.25rem', color: '#3ddc84' }} />
                                        <div className="flex-grow-1">
                                            <div className="fw-semibold">{settings.apk_original_name || 'app.apk'}</div>
                                            {settings.published_at && (
                                                <div className="small text-muted">Published {new Date(settings.published_at).toLocaleString()}</div>
                                            )}
                                        </div>
                                        <span className={`badge ${settings.force_update ? 'text-bg-danger' : 'text-bg-secondary'}`}>
                                            {settings.force_update ? 'Required' : 'Optional'}
                                        </span>
                                    </div>
                                    <table className="table table-sm mb-3">
                                        <tbody>
                                            <tr>
                                                <th className="text-muted fw-normal" style={{ width: '40%' }}>Latest version</th>
                                                <td className="fw-semibold">{settings.latest_version || '—'}</td>
                                            </tr>
                                            <tr>
                                                <th className="text-muted fw-normal">Version code</th>
                                                <td className="fw-semibold">{settings.latest_version_code}</td>
                                            </tr>
                                            <tr>
                                                <th className="text-muted fw-normal">Minimum supported</th>
                                                <td className="fw-semibold">{settings.minimum_version || '—'}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    {settings.release_notes && (
                                        <div className="mb-3">
                                            <div className="text-muted small mb-1">Release notes</div>
                                            <div className="small p-2 rounded bg-light border" style={{ whiteSpace: 'pre-wrap' }}>{settings.release_notes}</div>
                                        </div>
                                    )}
                                    <div className="d-flex gap-2">
                                        <a href={settings.apk_url} className="btn btn-sm btn-outline-dark">
                                            <i className="bi bi-download me-1" />Download
                                        </a>
                                        <button type="button" className="btn btn-sm btn-outline-danger" disabled={removing} onClick={removeApk}>
                                            <i className="bi bi-trash me-1" />{removing ? 'Removing…' : 'Remove'}
                                        </button>
                                    </div>
                                </>
                            ) : (
                                <div className="text-center text-muted p-4 mb-3 rounded bg-light border">
                                    <i className="bi bi-android2" style={{ fontSize: '2rem' }} />
                                    <p className="small mb-0 mt-2">No app uploaded yet. The login page won't show a download link until one is published.</p>
                                </div>
                            )}

                            <form onSubmit={publish} className="mt-3 pt-3 border-top vstack gap-3">
                                <div>
                                    <label className="form-label small text-muted">Upload APK (max 150MB)</label>
                                    <input ref={fileInput} type="file" accept=".apk" className="form-control"
                                        onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
                                    <div className="form-text">Leave blank to keep the current file and only republish its version info.</div>
                                </div>

                                <div className="row g-2">
                                    <div className="col-sm-6">
                                        <label className="form-label small text-muted">Latest version</label>
                                        <input className="form-control" placeholder="1.4.2"
                                            value={form.latest_version} onChange={(e) => set('latest_version', e.target.value)} />
                                    </div>
                                    <div className="col-sm-6">
                                        <label className="form-label small text-muted">Version code</label>
                                        <input type="number" min="0" className="form-control"
                                            value={form.latest_version_code} onChange={(e) => set('latest_version_code', e.target.value)} />
                                    </div>
                                </div>

                                <div className="row g-2">
                                    <div className="col-sm-6">
                                        <label className="form-label small text-muted">Update type</label>
                                        <select className="form-select" value={form.force_update ? 'required' : 'optional'}
                                            onChange={(e) => set('force_update', e.target.value === 'required')}>
                                            <option value="optional">Optional — conductors can update whenever</option>
                                            <option value="required">Required — the app forces the update</option>
                                        </select>
                                    </div>
                                    <div className="col-sm-6">
                                        <label className="form-label small text-muted">Minimum supported version</label>
                                        <input className="form-control" placeholder="1.0.0"
                                            value={form.minimum_version} onChange={(e) => set('minimum_version', e.target.value)} />
                                        <div className="form-text">Below this, the app is forced to update regardless.</div>
                                    </div>
                                </div>

                                <div>
                                    <label className="form-label small text-muted">Release notes</label>
                                    <textarea className="form-control" rows={3} placeholder={'- Fixed ticket synchronization\n- Improved GPS tracking'}
                                        value={form.release_notes} onChange={(e) => set('release_notes', e.target.value)} />
                                </div>

                                <details>
                                    <summary className="small text-muted" style={{ cursor: 'pointer' }}>Advanced</summary>
                                    <div className="vstack gap-3 mt-2">
                                        <div>
                                            <label className="form-label small text-muted">API base URL (override)</label>
                                            <input className="form-control" placeholder={`${window.location.origin}/api`}
                                                value={form.api_base_url} onChange={(e) => set('api_base_url', e.target.value)} />
                                            <div className="form-text">Leave blank to use the platform's Server Configuration.</div>
                                        </div>
                                        <div>
                                            <label className="form-label small text-muted">Download URL (external hosting)</label>
                                            <input className="form-control" placeholder="https://…/app.apk"
                                                value={form.download_url} onChange={(e) => set('download_url', e.target.value)} />
                                            <div className="form-text">Used instead of the uploaded APK, if set.</div>
                                        </div>
                                    </div>
                                </details>

                                <button type="submit" className="btn btn-primary" disabled={saving}>
                                    {saving && <span className="spinner-border spinner-border-sm me-2" />}
                                    <i className="bi bi-upload me-1" />Publish update
                                </button>
                                <div className="form-text mt-0">
                                    The download link stays the same across updates — no need to reshare anything.
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div className="col-xl-5">
                    <div className="card border-0 shadow-sm">
                        <div className="card-header bg-white">
                            <h2 className="h6 mb-0 fw-semibold">Share with conductors</h2>
                        </div>
                        <div className="card-body text-center">
                            {settings.apk_url ? (
                                <>
                                    <p className="text-muted small">
                                        Also shown directly on the login page. Use this QR code or link to share it separately.
                                    </p>
                                    <div className="d-flex justify-content-center my-3">
                                        <canvas ref={qrCanvas} />
                                    </div>
                                    <div className="input-group">
                                        <input type="text" className="form-control form-control-sm" value={settings.apk_url} readOnly />
                                        <button className="btn btn-sm btn-outline-secondary" type="button" onClick={copyLink}>
                                            <i className="bi bi-clipboard" />
                                        </button>
                                    </div>
                                </>
                            ) : (
                                <p className="text-muted small mb-0 mt-4">Publish the app to get a QR code and share link.</p>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
