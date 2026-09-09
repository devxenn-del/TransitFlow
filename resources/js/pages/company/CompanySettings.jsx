import { useEffect, useRef, useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import { companySettings } from '../../lib/api.js';
import { notifyError, notifySuccess } from '../../lib/ui.js';

function ConfigRow({ label, value }) {
    return (
        <div className="d-flex justify-content-between border-bottom py-1 small">
            <span className="text-muted">{label}</span>
            <span className="font-monospace">{String(value ?? '—')}</span>
        </div>
    );
}

const FIELDS = [
    'color_accent', 'color_accent_dark', 'receipt_width_mm', 'receipt_org_name',
    'ticket_footer', 'registration_number', 'otc_accreditation_number',
    'org_email', 'org_contact_number',
    'pdf_paper_size', 'pdf_orientation', 'pdf_margin_mm',
];

function toForm(s) {
    return Object.fromEntries(FIELDS.map((k) => [k, s[k] ?? '']));
}

function ImageField({ label, hint, url, editable, busy, onUpload, onClear }) {
    const input = useRef(null);
    return (
        <div className="d-flex gap-3 align-items-start">
            <div
                className="border rounded d-flex align-items-center justify-content-center bg-body-tertiary flex-shrink-0"
                style={{ width: 96, height: 96, overflow: 'hidden' }}
            >
                {url
                    ? <img src={url} alt={label} style={{ maxWidth: '100%', maxHeight: '100%' }} />
                    : <i className="bi bi-image text-muted fs-3" />}
            </div>
            <div>
                <label className="form-label mb-1">{label}</label>
                <div className="text-muted small mb-2">{hint}</div>
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
                            <i className="bi bi-upload me-1" /> Upload
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

export default function CompanySettings() {
    const { can } = useAuth();
    const editable = can('company.settings.manage');
    const [settings, setSettings] = useState(null);
    const [form, setForm] = useState(null);
    const [config, setConfig] = useState(null);
    const [saving, setSaving] = useState(false);
    const [imgBusy, setImgBusy] = useState(false);

    useEffect(() => {
        companySettings.get()
            .then((s) => { setSettings(s); setForm(toForm(s)); })
            .catch((e) => notifyError(e, 'Could not load company settings.'));
        companySettings.configuration().then(setConfig).catch(() => {});
    }, []);

    const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const updated = await companySettings.update(form);
            setSettings(updated);
            setForm(toForm(updated));
            notifySuccess('Settings saved.');
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const image = (type, action, ...args) => async () => {
        setImgBusy(true);
        try {
            const updated = await companySettings[action](type, ...args);
            setSettings(updated);
            notifySuccess('Image updated.');
        } catch (err) {
            notifyError(err);
        } finally {
            setImgBusy(false);
        }
    };

    if (!form) {
        return <div className="d-flex justify-content-center py-5"><span className="spinner-border text-primary" /></div>;
    }

    return (
        <>
            <PageHeader
                eyebrow="Company"
                title="Settings"
                subtitle="Branding, receipt text and organization identity — used on receipts and report letterheads."
            />

            <div className="row g-3">
                <div className="col-xl-7">
                    <form onSubmit={save} className="card border-0 shadow-sm">
                        <div className="card-body vstack gap-4">
                            <section>
                                <h2 className="h6 tf-eyebrow text-uppercase text-muted">Branding</h2>
                                <div className="row g-2">
                                    <div className="col-sm-6">
                                        <label className="form-label">Accent colour</label>
                                        <input type="color" className="form-control form-control-color" disabled={!editable}
                                            value={form.color_accent} onChange={(e) => set('color_accent', e.target.value)} />
                                    </div>
                                    <div className="col-sm-6">
                                        <label className="form-label">Accent colour (dark)</label>
                                        <input type="color" className="form-control form-control-color" disabled={!editable}
                                            value={form.color_accent_dark} onChange={(e) => set('color_accent_dark', e.target.value)} />
                                    </div>
                                </div>
                            </section>

                            <section className="vstack gap-3">
                                <ImageField
                                    label="Logo"
                                    hint="PNG / JPG / WEBP, max 2 MB. Shown on report letterheads."
                                    url={settings.logo_url}
                                    editable={editable}
                                    busy={imgBusy}
                                    onUpload={(f) => image('logo', 'uploadImage', f)()}
                                    onClear={image('logo', 'deleteImage')}
                                />
                                <ImageField
                                    label="QR payment image"
                                    hint="Displayed to passengers paying by QR."
                                    url={settings.qr_payment_url}
                                    editable={editable}
                                    busy={imgBusy}
                                    onUpload={(f) => image('qr-payment', 'uploadImage', f)()}
                                    onClear={image('qr-payment', 'deleteImage')}
                                />
                            </section>

                            <section>
                                <h2 className="h6 tf-eyebrow text-uppercase text-muted">Receipt</h2>
                                <div className="row g-2">
                                    <div className="col-sm-8">
                                        <label className="form-label">Organization name on receipts</label>
                                        <input className="form-control" disabled={!editable}
                                            value={form.receipt_org_name} onChange={(e) => set('receipt_org_name', e.target.value)} />
                                    </div>
                                    <div className="col-sm-4">
                                        <label className="form-label">Receipt width (mm)</label>
                                        <input type="number" step="0.5" min="40" max="120" className="form-control" disabled={!editable}
                                            value={form.receipt_width_mm} onChange={(e) => set('receipt_width_mm', e.target.value)} />
                                    </div>
                                    <div className="col-12">
                                        <label className="form-label">Ticket footer</label>
                                        <input className="form-control" disabled={!editable}
                                            value={form.ticket_footer} onChange={(e) => set('ticket_footer', e.target.value)} />
                                    </div>
                                </div>
                            </section>

                            <section>
                                <h2 className="h6 tf-eyebrow text-uppercase text-muted">Organization identity</h2>
                                <div className="row g-2">
                                    <div className="col-sm-6">
                                        <label className="form-label">Registration number</label>
                                        <input className="form-control" disabled={!editable}
                                            value={form.registration_number} onChange={(e) => set('registration_number', e.target.value)} />
                                    </div>
                                    <div className="col-sm-6">
                                        <label className="form-label">OTC accreditation number</label>
                                        <input className="form-control" disabled={!editable}
                                            value={form.otc_accreditation_number} onChange={(e) => set('otc_accreditation_number', e.target.value)} />
                                    </div>
                                    <div className="col-sm-6">
                                        <label className="form-label">Organization email</label>
                                        <input type="email" className="form-control" disabled={!editable}
                                            value={form.org_email} onChange={(e) => set('org_email', e.target.value)} />
                                    </div>
                                    <div className="col-sm-6">
                                        <label className="form-label">Contact number</label>
                                        <input className="form-control" disabled={!editable}
                                            value={form.org_contact_number} onChange={(e) => set('org_contact_number', e.target.value)} />
                                    </div>
                                </div>
                            </section>

                            <section>
                                <h2 className="h6 tf-eyebrow text-uppercase text-muted">Report PDF page setup</h2>
                                <div className="row g-2">
                                    <div className="col-sm-4">
                                        <label className="form-label">Paper size</label>
                                        <select className="form-select" disabled={!editable}
                                            value={form.pdf_paper_size} onChange={(e) => set('pdf_paper_size', e.target.value)}>
                                            <option value="a4">A4</option>
                                            <option value="letter">Letter</option>
                                            <option value="legal">Legal</option>
                                        </select>
                                    </div>
                                    <div className="col-sm-4">
                                        <label className="form-label">Orientation</label>
                                        <select className="form-select" disabled={!editable}
                                            value={form.pdf_orientation} onChange={(e) => set('pdf_orientation', e.target.value)}>
                                            <option value="landscape">Landscape</option>
                                            <option value="portrait">Portrait</option>
                                        </select>
                                    </div>
                                    <div className="col-sm-4">
                                        <label className="form-label">Margin (mm)</label>
                                        <input type="number" min="0" max="40" className="form-control" disabled={!editable}
                                            value={form.pdf_margin_mm} onChange={(e) => set('pdf_margin_mm', e.target.value)} />
                                    </div>
                                </div>
                            </section>
                        </div>
                        {editable && (
                            <div className="card-footer bg-white text-end">
                                <button className="btn btn-primary" disabled={saving}>
                                    {saving && <span className="spinner-border spinner-border-sm me-2" />}Save settings
                                </button>
                            </div>
                        )}
                    </form>
                </div>

                <div className="col-xl-5">
                    <div className="card border-0 shadow-sm">
                        <div className="card-body">
                            <h2 className="h6 tf-eyebrow text-uppercase text-muted">Receipt preview</h2>
                            <div className="border rounded p-3 bg-white font-monospace small" style={{ maxWidth: 320 }}>
                                <div className="text-center fw-bold text-uppercase">{form.receipt_org_name || 'Organization name'}</div>
                                {form.registration_number && <div className="text-center">Reg. No. {form.registration_number}</div>}
                                {form.otc_accreditation_number && <div className="text-center">OTC {form.otc_accreditation_number}</div>}
                                <hr className="my-2" />
                                <div>Ticket #000123</div>
                                <div>Regular · ₱15.00</div>
                                <hr className="my-2" />
                                <div className="text-center">{form.ticket_footer || 'Ticket footer'}</div>
                                {form.org_contact_number && <div className="text-center">{form.org_contact_number}</div>}
                            </div>
                        </div>
                    </div>

                    {config && (
                        <div className="card border-0 shadow-sm mt-3">
                            <div className="card-body">
                                <h2 className="h6 tf-eyebrow text-uppercase text-muted">Runtime configuration</h2>
                                <p className="small text-muted">Read-only — set by the deployment, shown here for reference.</p>
                                <ConfigRow label="Environment" value={config.platform.environment} />
                                <ConfigRow label="Timezone" value={config.platform.timezone} />
                                <ConfigRow label="DB timezone" value={config.platform.db_timezone} />
                                <ConfigRow label="Currency" value={config.platform.currency} />
                                <ConfigRow label="Laravel" value={config.platform.laravel_version} />
                                <ConfigRow label="PHP" value={config.platform.php_version} />
                                <ConfigRow label="Queue" value={config.platform.queue_connection} />
                                <ConfigRow label="Mailer" value={config.platform.mail_mailer} />
                                {config.features && (
                                    <>
                                        <ConfigRow label="Void feature" value={config.features.void_feature_enabled ? 'on' : 'off'} />
                                        <ConfigRow label="Void PIN required" value={config.features.void_pin_required ? 'yes' : 'no'} />
                                        <ConfigRow label="Can create accounts" value={config.company?.can_create_accounts ? 'yes' : 'no'} />
                                    </>
                                )}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
