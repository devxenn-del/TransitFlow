import { useCallback, useEffect, useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import DataTable from '../../components/DataTable.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import { voidSecurity } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

const dt = (v) => (v ? new Date(v).toLocaleString() : '—');

export default function VoidSecurity() {
    const { can } = useAuth();
    const manage = can('voidsecurity.manage');
    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(true);

    const load = useCallback(() => {
        setLoading(true);
        voidSecurity.list().then(setRows).catch(notifyError).finally(() => setLoading(false));
    }, []);
    useEffect(() => { load(); }, [load]);

    const attempts = useList(voidSecurity.attempts, { perPage: 25 });

    const reset = async (u) => {
        if (!(await confirmAction({
            title: `Reset ${u.name}'s void PIN?`,
            text: 'Their current PIN is cleared. They must set a new one from their account menu before they can authorise any void.',
            danger: true, confirmText: 'Reset PIN',
        }))) return;
        try { await voidSecurity.reset(u.id); notifySuccess('Void PIN cleared.'); load(); }
        catch (e) { notifyError(e); }
    };

    const unlock = async (u) => {
        try { await voidSecurity.unlock(u.id); notifySuccess('Lockout cleared.'); load(); }
        catch (e) { notifyError(e); }
    };

    const columns = [
        {
            key: 'user', header: 'Manager',
            render: (u) => <div><div className="fw-semibold">{u.name}</div><div className="small text-muted">{u.role} · {u.email}</div></div>,
        },
        {
            key: 'pin', header: 'Void PIN',
            render: (u) => (u.has_void_pin
                ? <span className="badge text-bg-success">set</span>
                : <span className="badge text-bg-secondary">not set</span>),
        },
        {
            key: 'state', header: 'Status',
            render: (u) => (u.locked
                ? <span className="badge text-bg-danger" title={`until ${dt(u.locked_until)}`}>locked · {u.failed_count} fails</span>
                : u.failed_count > 0
                    ? <span className="badge text-bg-warning">{u.failed_count} recent fails</span>
                    : <span className="text-muted small">ok</span>),
        },
        {
            key: 'actions', header: '', className: 'text-end text-nowrap',
            render: (u) => manage && (
                <>
                    {u.locked && (
                        <button className="btn btn-sm btn-outline-secondary me-1" onClick={() => unlock(u)}>
                            <i className="bi bi-unlock me-1" /> Unlock
                        </button>
                    )}
                    {u.has_void_pin && (
                        <button className="btn btn-sm btn-outline-danger" onClick={() => reset(u)}>
                            <i className="bi bi-arrow-counterclockwise me-1" /> Reset PIN
                        </button>
                    )}
                </>
            ),
        },
    ];

    const attemptColumns = [
        { key: 'when', header: 'When', render: (a) => <span className="small">{dt(a.attempted_at)}</span> },
        { key: 'mgr', header: 'Manager', render: (a) => a.manager ?? '—' },
        { key: 'subject', header: 'Target', render: (a) => <code className="small">{a.subject ?? '—'}</code> },
        {
            key: 'result', header: 'Result',
            render: (a) => (a.success
                ? <span className="badge text-bg-success">authorised</span>
                : <span className="badge text-bg-danger">{a.detail ?? 'failed'}</span>),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Company"
                title="Void Security"
                subtitle="Who holds a void PIN, who's locked out, and every void-authorisation attempt. Managers set their own PIN from the account menu."
            />

            <DataTable columns={columns} rows={rows} loading={loading} empty="No managers hold void-PIN access yet." />

            <h2 className="h6 mt-4 mb-2">Authorisation attempts</h2>
            <DataTable columns={attemptColumns} rows={attempts.rows} loading={attempts.loading} empty="No void attempts recorded." />
            <Pagination meta={attempts.meta} page={attempts.page} onPage={attempts.setPage} />
        </>
    );
}
