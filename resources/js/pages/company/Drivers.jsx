import { useEffect, useRef, useState } from 'react';
import QRCode from 'qrcode';

import { useAuth } from '../../auth/AuthContext.jsx';
import DataTable from '../../components/DataTable.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import StatusBadge from '../../components/StatusBadge.jsx';
import { drivers } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

const BLANK = { name: '', license_number: '', contact_number: '', status: 'Active', driver_code: '' };

/**
 * The driver's QR just encodes their existing driver_code text — scanning
 * it at login is equivalent to typing that code (see LoginFragment's / web
 * Login's Verify Driver step). Printable card, company-branded, for the
 * driver to carry — any conductor holding it may use it, not just one
 * paired conductor. `.driver-qr-print-area` (resources/css/app.css) makes
 * window.print() print only this card, not the surrounding admin page.
 */
function DriverQrModal({ driver, company, onClose }) {
    const canvasRef = useRef(null);

    useEffect(() => {
        if (!driver || !canvasRef.current) return;
        QRCode.toCanvas(canvasRef.current, driver.driver_code, { width: 220, margin: 1 }).catch(() => {});
    }, [driver]);

    return (
        <Modal
            open={!!driver}
            title={driver ? `${driver.name} — QR Code` : ''}
            onClose={onClose}
            footer={<button className="btn btn-primary" onClick={() => window.print()}><i className="bi bi-printer me-1" />Print</button>}
        >
            <div className="driver-qr-print-area">
                <div className="driver-qr-print-header">
                    {company?.logo_url && <img src={company.logo_url} alt="" className="driver-qr-print-logo" />}
                    <div className="driver-qr-print-company">{company?.name ?? 'TransitFlow'}</div>
                </div>
                <div className="driver-qr-print-name d-print-none">{driver?.name}</div>
                <canvas ref={canvasRef} />
                <div className="driver-qr-print-code">{driver?.driver_code}</div>
                <p className="driver-qr-print-hint d-print-none">
                    Any conductor can scan this QR code — or type the code above — to verify this driver at sign-in.
                    Printing produces a 6cm × 6cm label.
                </p>
            </div>
        </Modal>
    );
}

export default function Drivers() {
    const { can, user } = useAuth();
    const { rows, meta, loading, page, setPage, reload } = useList(drivers.list);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(BLANK);
    const [saving, setSaving] = useState(false);
    const [qrDriver, setQrDriver] = useState(null);

    const openNew = () => { setForm(BLANK); setEditing({}); };
    const openEdit = (d) => {
        setForm({
            name: d.name,
            license_number: d.license_number ?? '',
            contact_number: d.contact_number ?? '',
            status: d.status,
            driver_code: d.driver_code ?? '',
        });
        setEditing(d);
    };

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            // Blank on create leaves it to auto-generate (DR-YYMM-XXXX-XXXX);
            // blank on edit is a real (validated, rejected) request, never
            // silently dropped — a conductor may already be depending on
            // that code.
            const payload = { ...form };
            if (!editing.id && !payload.driver_code) delete payload.driver_code;

            if (editing.id) {
                await drivers.update(editing.id, payload);
                notifySuccess('Driver updated.');
            } else {
                await drivers.create(payload);
                notifySuccess('Driver added.');
            }
            setEditing(null);
            reload();
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const remove = async (d) => {
        if (!(await confirmAction({ title: `Delete ${d.name}?`, danger: true, confirmText: 'Delete' }))) return;
        try {
            await drivers.remove(d.id);
            notifySuccess('Driver deleted.');
            reload();
        } catch (err) {
            notifyError(err);
        }
    };

    const columns = [
        { key: 'name', header: 'Driver', className: 'fw-semibold' },
        { key: 'employee_id', header: 'Employee ID', render: (d) => <code>{d.employee_id ?? '—'}</code> },
        { key: 'driver_code', header: 'Driver Code', render: (d) => <code>{d.driver_code ?? '—'}</code> },
        { key: 'license_number', header: 'License #', render: (d) => d.license_number || <span className="text-muted">—</span> },
        { key: 'contact_number', header: 'Contact', render: (d) => d.contact_number || <span className="text-muted">—</span> },
        { key: 'status', header: 'Status', render: (d) => <StatusBadge value={d.status.toLowerCase()} /> },
        {
            key: 'actions',
            header: '',
            className: 'text-end text-nowrap',
            render: (d) => (
                <>
                    {d.driver_code && (
                        <button className="btn btn-sm btn-outline-secondary me-1" title="Show QR code" onClick={() => setQrDriver(d)}>
                            <i className="bi bi-qr-code" />
                        </button>
                    )}
                    {can('drivers.edit') && (
                        <button className="btn btn-sm btn-outline-secondary me-1" onClick={() => openEdit(d)}>
                            <i className="bi bi-pencil" />
                        </button>
                    )}
                    {can('drivers.delete') && (
                        <button className="btn btn-sm btn-outline-danger" onClick={() => remove(d)}>
                            <i className="bi bi-trash" />
                        </button>
                    )}
                </>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Fleet"
                title="Drivers"
                subtitle="Login-free records. A conductor picks the driver when starting a trip."
                actions={can('drivers.create') && (
                    <button className="btn btn-accent" onClick={openNew}>
                        <i className="bi bi-plus-lg me-1" /> Add driver
                    </button>
                )}
            />

            <DataTable columns={columns} rows={rows} loading={loading} empty="No drivers yet." />
            <Pagination meta={meta} page={page} onPage={setPage} />

            <Modal
                open={!!editing}
                title={editing?.id ? `Edit ${editing.name}` : 'Add driver'}
                onClose={() => setEditing(null)}
                footer={
                    <>
                        <button className="btn btn-light" onClick={() => setEditing(null)}>Cancel</button>
                        <button className="btn btn-primary" form="driver-form" disabled={saving}>
                            {saving && <span className="spinner-border spinner-border-sm me-2" />}Save
                        </button>
                    </>
                }
            >
                <form id="driver-form" onSubmit={save} className="vstack gap-3">
                    <div>
                        <label className="form-label">Name *</label>
                        <input className="form-control" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value.toUpperCase() })} />
                    </div>
                    <div className="row g-2">
                        <div className="col-md-6">
                            <label className="form-label">License number</label>
                            <input className="form-control" value={form.license_number} onChange={(e) => setForm({ ...form, license_number: e.target.value.toUpperCase() })} />
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Contact number</label>
                            <input className="form-control" value={form.contact_number} onChange={(e) => setForm({ ...form, contact_number: e.target.value })} />
                        </div>
                    </div>
                    <div>
                        <label className="form-label">Status</label>
                        <select className="form-select" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                    <div>
                        <label className="form-label">Driver Code</label>
                        <input
                            className="form-control"
                            placeholder="Leave blank to auto-generate"
                            value={form.driver_code}
                            onChange={(e) => setForm({ ...form, driver_code: e.target.value.toUpperCase() })}
                        />
                        <div className="form-text">
                            Any conductor can scan or enter this code at sign-in to verify they're working with this
                            driver. Unique per company.
                        </div>
                    </div>
                    {!editing?.id && <p className="small text-muted mb-0">An employee ID is generated automatically.</p>}
                </form>
            </Modal>

            <DriverQrModal driver={qrDriver} company={user?.company} onClose={() => setQrDriver(null)} />
        </>
    );
}
