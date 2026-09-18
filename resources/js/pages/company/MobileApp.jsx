import { useEffect, useState } from 'react';

import PageHeader from '../../components/PageHeader.jsx';
import { mobileApp } from '../../lib/api.js';
import { notifyError } from '../../lib/ui.js';

/**
 * Read-only — the mobile app is published platform-wide by the Super Admin
 * (one build, every company). This just shows what's currently live.
 */
export default function MobileApp() {
    const [settings, setSettings] = useState(null);

    useEffect(() => {
        mobileApp.get()
            .then(setSettings)
            .catch((e) => notifyError(e, 'Could not load mobile-app settings.'));
    }, []);

    if (!settings) {
        return <div className="d-flex justify-content-center py-5"><span className="spinner-border text-primary" /></div>;
    }

    return (
        <>
            <PageHeader
                eyebrow="Company"
                title="Mobile App"
                subtitle="The conductor app is the same build for every company, published by the platform administrator."
            />

            <div className="row g-3">
                <div className="col-xl-7">
                    <div className="card border-0 shadow-sm">
                        <div className="card-body vstack gap-3">
                            <div className="row g-2">
                                <div className="col-sm-6">
                                    <div className="small text-muted">Latest version</div>
                                    <div className="fw-semibold">{settings.latest_version || '—'}</div>
                                </div>
                                <div className="col-sm-6">
                                    <div className="small text-muted">Minimum supported version</div>
                                    <div className="fw-semibold">{settings.minimum_version || '—'}</div>
                                </div>
                                <div className="col-sm-6">
                                    <div className="small text-muted">Force update</div>
                                    <div className="fw-semibold">{settings.force_update ? 'Yes' : 'No'}</div>
                                </div>
                                <div className="col-sm-6">
                                    <div className="small text-muted">Published</div>
                                    <div className="fw-semibold">{settings.published_at ? new Date(settings.published_at).toLocaleString() : '—'}</div>
                                </div>
                            </div>
                            {settings.release_notes && (
                                <div>
                                    <div className="small text-muted">Release notes</div>
                                    <p className="mb-0">{settings.release_notes}</p>
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                <div className="col-xl-5">
                    <div className="card border-0 shadow-sm">
                        <div className="card-body">
                            <h2 className="h6 tf-eyebrow text-uppercase text-muted">APK file</h2>
                            {settings.apk_url ? (
                                <p className="small mb-2">
                                    <i className="bi bi-file-earmark-zip me-1" />
                                    <a href={settings.apk_url} target="_blank" rel="noreferrer">current APK</a>
                                </p>
                            ) : (
                                <p className="small text-muted mb-2">No APK uploaded.</p>
                            )}
                            <p className="small text-muted mb-0">
                                <i className="bi bi-info-circle me-1" />
                                Hosted by the platform administrator — the same file for every company. Conductors
                                can get it from the login page using this company's code.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
