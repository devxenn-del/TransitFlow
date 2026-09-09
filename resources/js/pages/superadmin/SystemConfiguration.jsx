import { useEffect, useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import DataTable from '../../components/DataTable.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import { systemConfiguration } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

const dt = (v) => (v ? new Date(v).toLocaleString() : '—');

const STATUS_BADGE = {
    connected: <span className="badge text-bg-success"><i className="bi bi-check-circle me-1" />Connected</span>,
    unset: <span className="badge text-bg-secondary">Not configured</span>,
};

/**
 * Super Admin "System Configuration" — the platform-wide default API base
 * URL the mobile app is told to use when a company hasn't set its own
 * override (BITS-era single-server assumption → §K). Every save is
 * validated + connection-tested before it takes effect; a failed attempt
 * never overwrites the working configuration.
 */
export default function SystemConfiguration() {
    const { can } = useAuth();
    const manage = can('system.configuration.manage');

    const [config, setConfig] = useState(null);
    const [url, setUrl] = useState('');
    const [testing, setTesting] = useState(false);
    const [testResult, setTestResult] = useState(null);
    const [saving, setSaving] = useState(false);

    const history = useList(systemConfiguration.history, { perPage: 10 });

    const load = () => {
        systemConfiguration.get()
            .then((c) => { setConfig(c); setUrl(c.api_base_url ?? ''); })
            .catch((err) => notifyError(err, 'Could not load the server configuration.'));
    };
    useEffect(load, []);

    const testConnection = async () => {
        setTesting(true);
        setTestResult(null);
        try {
            const result = await systemConfiguration.test(url);
            setTestResult(result);
        } catch (err) {
            setTestResult({ ok: false, message: err.response?.data?.errors?.url?.[0] ?? 'Could not test that address.' });
        } finally {
            setTesting(false);
        }
    };

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const updated = await systemConfiguration.update({ api_base_url: url });
            setConfig(updated);
            setTestResult(null);
            notifySuccess('Server configuration saved.');
            history.reload();
        } catch (err) {
            notifyError(err, 'Could not save — the server did not pass verification.');
        } finally {
            setSaving(false);
        }
    };

    const rollback = async (row) => {
        if (!(await confirmAction({
            title: 'Roll back the server configuration?',
            text: `Restore ${row.previous_value ?? '(not configured)'}? This is re-tested before it takes effect.`,
            danger: true,
            confirmText: 'Roll back',
        }))) return;
        try {
            const updated = await systemConfiguration.rollback(row.id);
            setConfig(updated);
            setUrl(updated.api_base_url ?? '');
            notifySuccess('Rolled back.');
            history.reload();
        } catch (err) {
            notifyError(err, 'That previous server did not pass re-verification.');
        }
    };

    const historyColumns = [
        { key: 'when', header: 'Date and time', render: (r) => <span className="small">{dt(r.created_at)}</span> },
        { key: 'previous', header: 'Previous server', render: (r) => <code className="small">{r.previous_value ?? '—'}</code> },
        { key: 'new', header: 'New server', render: (r) => <code className="small">{r.new_value ?? '—'}</code> },
        { key: 'by', header: 'Changed by', render: (r) => r.changed_by ?? '—' },
        {
            key: 'status', header: 'Status',
            render: (r) => ({
                validated: <span className="badge text-bg-success">Successfully validated</span>,
                failed: <span className="badge text-bg-danger" title={r.reason ?? ''}>Failed</span>,
                rolled_back: <span className="badge text-bg-info">Rolled back</span>,
            })[r.status] ?? r.status,
        },
        {
            key: 'actions', header: '', className: 'text-end',
            render: (r) => manage && r.can_rollback && (
                <button className="btn btn-sm btn-outline-secondary" onClick={() => rollback(r)}>
                    <i className="bi bi-arrow-counterclockwise me-1" />Rollback
                </button>
            ),
        },
    ];

    if (!config) {
        return <div className="d-flex justify-content-center py-5"><span className="spinner-border text-primary" /></div>;
    }

    return (
        <>
            <PageHeader
                eyebrow="Platform"
                title="System Configuration"
                subtitle="The default API base URL TransitFlow's mobile app uses when a company hasn't set its own override."
            />

            <div className="row">
                <div className="col-xl-8">
                    <form onSubmit={save} className="card border-0 shadow-sm mb-4">
                        <div className="card-header bg-white fw-semibold">Server connection</div>
                        <div className="card-body vstack gap-3">
                            <div>
                                <label className="form-label">Server Base URL</label>
                                <input
                                    className="form-control font-monospace"
                                    placeholder="https://api.transitflow.com/api"
                                    value={url}
                                    disabled={!manage}
                                    onChange={(e) => { setUrl(e.target.value); setTestResult(null); }}
                                />
                            </div>

                            {testResult && (
                                <div className={`small ${testResult.ok ? 'text-success' : 'text-danger'}`}>
                                    <i className={`bi ${testResult.ok ? 'bi-check-circle' : 'bi-x-circle'} me-1`} />
                                    {testResult.message}
                                    {testResult.latency_ms != null && ` (${testResult.latency_ms} ms)`}
                                </div>
                            )}

                            <div className="row g-2 small text-body-secondary">
                                <div className="col-sm-4">
                                    <div className="text-uppercase small fw-semibold">Status</div>
                                    {STATUS_BADGE[config.status] ?? config.status}
                                </div>
                                <div className="col-sm-4">
                                    <div className="text-uppercase small fw-semibold">Last verified</div>
                                    {dt(config.last_verified_at)}
                                </div>
                                <div className="col-sm-4">
                                    <div className="text-uppercase small fw-semibold">Configuration version</div>
                                    Version {config.configuration_version}
                                </div>
                            </div>
                        </div>
                        {manage && (
                            <div className="card-footer bg-white d-flex justify-content-end gap-2">
                                <button type="button" className="btn btn-outline-secondary" disabled={testing || !url} onClick={testConnection}>
                                    {testing && <span className="spinner-border spinner-border-sm me-2" />}Test Connection
                                </button>
                                <button className="btn btn-primary" disabled={saving || !url}>
                                    {saving && <span className="spinner-border spinner-border-sm me-2" />}Save Changes
                                </button>
                            </div>
                        )}
                    </form>
                </div>
            </div>

            <h2 className="h6 mb-2">Configuration history</h2>
            <DataTable columns={historyColumns} rows={history.rows} loading={history.loading} empty="No configuration changes yet." />
            <Pagination meta={history.meta} page={history.page} onPage={history.setPage} />
        </>
    );
}
