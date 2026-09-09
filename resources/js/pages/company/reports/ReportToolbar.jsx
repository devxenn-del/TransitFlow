import { useState } from 'react';

import { reports } from '../../../lib/api.js';
import { notifyError } from '../../../lib/ui.js';

/**
 * Filter bar + PDF / Excel export buttons shared by every report page.
 *
 * `reportKey` is the API slug (income | daily-operations | …); `params` are
 * the current filter values, forwarded to the export endpoints.
 */
export default function ReportToolbar({ reportKey, params, onRun, running, children }) {
    const [downloading, setDownloading] = useState(null);

    const download = async (format) => {
        setDownloading(format);
        try {
            await reports.download(reportKey, format, params);
        } catch (e) {
            notifyError(e);
        } finally {
            setDownloading(null);
        }
    };

    return (
        <div className="d-flex flex-wrap gap-2 mb-3 align-items-end">
            {children}
            <div className="ms-auto d-flex gap-2 align-items-end">
                {onRun && (
                    <button className="btn btn-sm btn-accent" onClick={onRun} disabled={running}>
                        <i className="bi bi-arrow-repeat me-1" /> Run
                    </button>
                )}
                <button className="btn btn-sm btn-outline-danger" onClick={() => download('pdf')} disabled={downloading}>
                    <i className="bi bi-file-earmark-pdf me-1" />
                    {downloading === 'pdf' ? 'Preparing…' : 'PDF'}
                </button>
                <button className="btn btn-sm btn-outline-success" onClick={() => download('xlsx')} disabled={downloading}>
                    <i className="bi bi-file-earmark-excel me-1" />
                    {downloading === 'xlsx' ? 'Preparing…' : 'Excel'}
                </button>
            </div>
        </div>
    );
}
