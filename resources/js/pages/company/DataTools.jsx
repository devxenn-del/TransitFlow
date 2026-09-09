import { useCallback, useEffect, useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import { dataTools } from '../../lib/api.js';
import { swal as Swal } from '../../lib/ui.js';
import { notifyError, notifySuccess } from '../../lib/ui.js';

const dt = (v) => (v ? new Date(v).toLocaleString() : '—');

export default function DataTools() {
    const { can, user } = useAuth();
    const canExport = can('backup.download');
    const canClean = can('cleandata.run');
    const code = user?.company?.code ?? '';

    const [preview, setPreview] = useState(null);
    const [history, setHistory] = useState([]);
    const [busy, setBusy] = useState(false);

    const loadHistory = useCallback(() => {
        dataTools.history().then(setHistory).catch(() => {});
    }, []);

    useEffect(() => {
        loadHistory();
        if (canClean) dataTools.cleanPreview().then(setPreview).catch(() => {});
    }, [canClean, loadHistory]);

    const doExport = async () => {
        setBusy(true);
        try {
            await dataTools.export();
            notifySuccess('Export downloaded.');
            loadHistory();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    const doClean = async () => {
        const { isConfirmed, value } = await Swal.fire({
            title: 'Clean Data',
            html: `<p class="text-start small">This permanently deletes <b>${preview?.total ?? 0}</b> transactional records `
                + '(trips, tickets, remittances, attendance, fuel, expenses, GPS). Master data — buses, drivers, terminals, '
                + 'fares, users, settings — is kept. <b>This cannot be undone.</b></p>'
                + `<input id="confirm" class="form-control" placeholder="Type ${code} to confirm" autocomplete="off">`,
            focusConfirm: false,
            showCancelButton: true,
            confirmButtonText: 'Wipe transactional data',
            customClass: { confirmButton: 'btn btn-danger px-4', cancelButton: 'btn btn-light px-4' },
            preConfirm: () => {
                const v = document.getElementById('confirm').value;
                if (v !== code) { Swal.showValidationMessage('Company code does not match'); return false; }
                return v;
            },
        });
        if (!isConfirmed) return;
        setBusy(true);
        try {
            const res = await dataTools.clean(value);
            notifySuccess(`Removed ${res.total} records.`);
            dataTools.cleanPreview().then(setPreview).catch(() => {});
            loadHistory();
        } catch (e) {
            notifyError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <PageHeader
                eyebrow="Company"
                title="Data Tools"
                subtitle="Export a full backup of this company's data, or clear its transactional records for a fresh start."
            />

            <div className="row g-3">
                <div className="col-lg-6">
                    <div className="card border-0 shadow-sm h-100">
                        <div className="card-body">
                            <h2 className="h6 tf-eyebrow text-uppercase text-muted">Export (backup)</h2>
                            <p className="small text-muted">
                                Downloads a JSON file with every row this company owns — master and transactional.
                            </p>
                            {canExport ? (
                                <button className="btn btn-outline-primary" disabled={busy} onClick={doExport}>
                                    <i className="bi bi-download me-1" /> Export company data
                                </button>
                            ) : (
                                <p className="small text-muted mb-0">You don't have permission to export.</p>
                            )}
                        </div>
                    </div>
                </div>

                <div className="col-lg-6">
                    <div className="card border-danger-subtle shadow-sm h-100">
                        <div className="card-body">
                            <h2 className="h6 tf-eyebrow text-uppercase text-danger">Danger zone — Clean Data</h2>
                            {canClean ? (
                                <>
                                    <p className="small text-muted">
                                        Permanently deletes{' '}
                                        <b>{preview ? preview.total : '…'}</b> transactional records. Master data is kept.
                                    </p>
                                    <button className="btn btn-outline-danger" disabled={busy || !preview} onClick={doClean}>
                                        <i className="bi bi-trash me-1" /> Clean transactional data
                                    </button>
                                </>
                            ) : (
                                <p className="small text-muted mb-0">You don't have permission to run Clean Data.</p>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <h2 className="h6 tf-eyebrow text-uppercase text-muted mt-4">History</h2>
            <div className="card border-0 shadow-sm">
                <div className="table-responsive">
                    <table className="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr><th>When</th><th>Operation</th><th>By</th><th className="text-end">Rows</th></tr>
                        </thead>
                        <tbody>
                            {history.length === 0 && (
                                <tr><td colSpan={4} className="tf-empty">No exports or clean-ups yet.</td></tr>
                            )}
                            {history.map((op) => (
                                <tr key={op.id}>
                                    <td className="small">{dt(op.created_at)}</td>
                                    <td>
                                        <span className={`badge ${op.type === 'clean' ? 'text-bg-danger' : 'text-bg-secondary'}`}>
                                            {op.type}
                                        </span>
                                    </td>
                                    <td className="small">{op.performed_by_name ?? '—'}</td>
                                    <td className="text-end">{op.total ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
