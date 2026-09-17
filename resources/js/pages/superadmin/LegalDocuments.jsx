import { useEffect, useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import DataTable from '../../components/DataTable.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import { legalDocuments } from '../../lib/api.js';
import { notifyError, notifySuccess } from '../../lib/ui.js';

const TYPES = [
    { key: 'privacy_policy', label: 'Privacy Policy' },
    { key: 'terms_of_use', label: 'Terms of Use' },
];

const dt = (v) => (v ? new Date(v).toLocaleString() : '—');

function DocumentEditor({ type, label, manage, onPublished }) {
    const [active, setActive] = useState(undefined); // undefined = loading, null = none yet
    const [form, setForm] = useState({ title: label, content: '', effective_date: '' });
    const [saving, setSaving] = useState(false);

    const load = () => {
        legalDocuments.active(type)
            .then((doc) => {
                setActive(doc);
                setForm({ title: doc.title, content: doc.content, effective_date: doc.effective_date });
            })
            .catch(() => setActive(null));
    };
    useEffect(load, [type]);

    const publish = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const doc = await legalDocuments.publish({ type, ...form });
            setActive(doc);
            notifySuccess(`${label} version ${doc.version} published.`);
            onPublished();
        } catch (err) {
            notifyError(err, `Could not publish the ${label}.`);
        } finally {
            setSaving(false);
        }
    };

    if (active === undefined) {
        return <div className="d-flex justify-content-center py-4"><span className="spinner-border text-primary" /></div>;
    }

    return (
        <form onSubmit={publish} className="card border-0 shadow-sm mb-4">
            <div className="card-header bg-white d-flex justify-content-between align-items-center">
                <span className="fw-semibold">{label}</span>
                {active && (
                    <span className="small text-body-secondary">
                        Current: version {active.version} · effective {active.effective_date}
                    </span>
                )}
            </div>
            <div className="card-body vstack gap-3">
                <div>
                    <label className="form-label">Title</label>
                    <input
                        className="form-control"
                        value={form.title}
                        disabled={!manage}
                        onChange={(e) => setForm({ ...form, title: e.target.value })}
                        required
                    />
                </div>
                <div>
                    <label className="form-label">Effective date</label>
                    <input
                        type="date"
                        className="form-control"
                        style={{ maxWidth: 220 }}
                        value={form.effective_date}
                        disabled={!manage}
                        onChange={(e) => setForm({ ...form, effective_date: e.target.value })}
                        required
                    />
                </div>
                <div>
                    <label className="form-label">
                        Content
                        <span className="text-body-secondary fw-normal"> — a line starting with "## " is rendered as a section heading</span>
                    </label>
                    <textarea
                        className="form-control font-monospace small"
                        rows={16}
                        value={form.content}
                        disabled={!manage}
                        onChange={(e) => setForm({ ...form, content: e.target.value })}
                        required
                    />
                </div>
            </div>
            {manage && (
                <div className="card-footer bg-white d-flex justify-content-end">
                    <button className="btn btn-primary" disabled={saving}>
                        {saving && <span className="spinner-border spinner-border-sm me-2" />}
                        Publish new version
                    </button>
                </div>
            )}
        </form>
    );
}

/**
 * Super Admin "Legal Documents" — publish/version the Privacy Policy and
 * Terms of Use. Publishing a new version deactivates the previous one but
 * never deletes it; a user who already accepted an older version is asked
 * to re-accept on their next login (App\Models\User::needsLegalAcceptance).
 */
export default function LegalDocuments() {
    const { can } = useAuth();
    const manage = can('legal.manage');
    const [history, setHistory] = useState([]);

    const loadHistory = () => {
        legalDocuments.list()
            .then(setHistory)
            .catch((err) => notifyError(err, 'Could not load the version history.'));
    };
    useEffect(loadHistory, []);

    const historyColumns = [
        { key: 'type', header: 'Document', render: (r) => TYPES.find((t) => t.key === r.type)?.label ?? r.type },
        { key: 'version', header: 'Version' },
        { key: 'effective_date', header: 'Effective date' },
        { key: 'published_by', header: 'Published by', render: (r) => r.published_by ?? '—' },
        { key: 'created_at', header: 'Published at', render: (r) => dt(r.created_at) },
        {
            key: 'status', header: 'Status',
            render: (r) => (r.is_active
                ? <span className="badge text-bg-success">Active</span>
                : <span className="badge text-bg-secondary">Superseded</span>),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Platform"
                title="Legal Documents"
                subtitle="Publish and version the Privacy Policy and Terms of Use shown across the mobile app and web portal."
            />

            {TYPES.map((t) => (
                <DocumentEditor key={t.key} type={t.key} label={t.label} manage={manage} onPublished={loadHistory} />
            ))}

            <h2 className="h6 mb-2">Version history</h2>
            <DataTable columns={historyColumns} rows={history} empty="No legal documents published yet." />
        </>
    );
}
