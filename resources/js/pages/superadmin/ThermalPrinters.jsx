import { useCallback, useEffect, useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import PrinterStatusBadge, { PRINTER_STATUSES } from '../../components/PrinterStatusBadge.jsx';
import SearchableSelect from '../../components/SearchableSelect.jsx';
import { SkeletonTableRows } from '../../components/Skeleton.jsx';
import { companies, platformThermalPrinters as printersApi } from '../../lib/api.js';
import { useList } from '../../lib/useList.js';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

const ASSIGNMENTS = [
    ['', 'Issued & unissued'],
    ['assigned', 'Issued to a company'],
    ['no_bus', 'Issued, no bus yet'],
    ['unassigned', 'Not issued'],
];

/** Statuses a printer may be (re)issued to a company from — App\Enums\ThermalPrinterStatus::acceptsCompany(). */
const ACCEPTS_COMPANY = ['Available', 'Returned', 'Assigned'];

/** What each target status does to the printer's links (App\Actions\ChangeThermalPrinterStatus). */
const STATUS_EFFECT = {
    Available: 'Back in the pool, ready to issue.',
    Assigned: 'Back in service with its company. Assign a bus again if needed.',
    Returned: 'Back with the administrator. Its company, bus and issue date are cleared.',
    Defective: 'Reported faulty. It keeps its company but is taken off its bus.',
    'Under Maintenance': 'Being repaired or inspected. It keeps its company but is taken off its bus.',
    Retired: 'Permanently out of service. This cannot be undone.',
};

const ACTION_LABELS = {
    registered: 'Registered',
    company_assigned: 'Issued to company',
    company_changed: 'Moved to another company',
    bus_assigned: 'Assigned to bus',
    bus_changed: 'Moved to another bus',
    bus_removed: 'Removed from bus',
    status_changed: 'Status changed',
    conductor_unlinked: 'Conductor link retired',
};

const today = () => new Date().toLocaleDateString('en-CA');
const fmtDate = (v) => (v ? new Date(`${String(v).slice(0, 10)}T00:00:00`).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '—');
const fmtDateTime = (v) => (v ? new Date(v).toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—');
const muted = (text) => <span className="text-body-secondary">{text}</span>;
const busLabel = (bus) => (bus ? `${bus.bus_number}${bus.plate_number ? ` · ${bus.plate_number}` : ''}` : null);
const notAssigned = muted('Not Assigned');

/** A company picker that searches the server as you type. */
function useCompanyOptions(open) {
    const [options, setOptions] = useState([]);
    const [q, setQ] = useState('');

    useEffect(() => {
        if (!open) return undefined;
        let active = true;
        const t = setTimeout(() => {
            companies.list({ q: q || undefined, per_page: 50 })
                .then((res) => active && setOptions((res.data ?? []).map((c) => ({ value: String(c.id), label: `${c.name} (${c.code})${c.status !== 'active' ? ` — ${c.status}` : ''}` }))))
                .catch((err) => active && notifyError(err, 'Could not load companies.'));
        }, 250);
        return () => { active = false; clearTimeout(t); };
    }, [q, open]);

    return { options, search: setQ };
}

/** The read-only identity block shown at the top of every printer form. */
function Identity({ printer, showCompany }) {
    return (
        <div className="row g-2 small mb-3">
            <div className="col-6">
                <label className="form-label mb-0 text-body-secondary" htmlFor="tp-thermal-id">Thermal ID</label>
                <input id="tp-thermal-id" className="form-control form-control-sm font-monospace" value={printer.thermal_id} readOnly />
            </div>
            <div className="col-6">
                <label className="form-label mb-0 text-body-secondary" htmlFor="tp-device-id">Device ID</label>
                <input id="tp-device-id" className="form-control form-control-sm font-monospace" value={printer.device_id} readOnly />
            </div>
            <div className="col-6"><span className="text-body-secondary">MAC address</span><div>{printer.mac_address ? <code>{printer.mac_address}</code> : '—'}</div></div>
            <div className="col-6"><span className="text-body-secondary">Model</span><div>{printer.model}</div></div>
            {showCompany && <div className="col-12"><span className="text-body-secondary">Assigned company</span><div className="fw-semibold">{printer.company?.name ?? 'Not Assigned'}</div></div>}
        </div>
    );
}

function SaveButton({ form, saving, children = 'Save', disabled }) {
    return (
        <button className="btn btn-primary" form={form} disabled={saving || disabled}>
            {saving && <span className="spinner-border spinner-border-sm me-2" />}{children}
        </button>
    );
}

/**
 * Thermal Printer Management — the platform's printer inventory across
 * every company. Register printers (IDs are generated by the server),
 * issue them to a company, put each one on a specific bus of that company,
 * and track status and history. Every rule is enforced by the API; this
 * page only offers the choices the API will accept.
 */
export default function ThermalPrinters() {
    const { can } = useAuth();
    const canManage = can('printers.inventory.manage');

    // --- Filters -----------------------------------------------------------
    const [search, setSearch] = useState('');
    const [q, setQ] = useState('');
    const [status, setStatus] = useState('');
    const [assignment, setAssignment] = useState('');
    const [companyId, setCompanyId] = useState('');
    const [busId, setBusId] = useState('');
    const [filterBuses, setFilterBuses] = useState([]);
    const filterCompanies = useCompanyOptions(true);

    const { rows, meta, loading, page, setPage, reload } = useList(
        (params) => printersApi.list({
            ...params,
            q: q || undefined,
            status: status || undefined,
            assignment: assignment || undefined,
            company_id: companyId || undefined,
            bus_id: busId || undefined,
        }),
        { deps: [q, status, assignment, companyId, busId] },
    );

    useEffect(() => {
        const t = setTimeout(() => setQ(search.trim()), 300);
        return () => clearTimeout(t);
    }, [search]);

    useEffect(() => {
        setBusId('');
        setFilterBuses([]);
        if (!companyId) return;
        printersApi.buses(companyId).then(setFilterBuses).catch((err) => notifyError(err, 'Could not load buses.'));
    }, [companyId]);

    const filtered = !!(q || status || assignment || companyId);
    const clearFilters = () => { setSearch(''); setStatus(''); setAssignment(''); setCompanyId(''); };

    // --- Register / edit ---------------------------------------------------
    const [editing, setEditing] = useState(null); // null | {} (new) | printer
    const [form, setForm] = useState({});
    const [saving, setSaving] = useState(false);

    const openNew = () => { setForm({ model: '', mac_address: '', serial_number: '', remarks: '' }); setEditing({}); };
    const openEdit = (p) => {
        setForm({ model: p.model, mac_address: p.mac_address ?? '', serial_number: p.serial_number ?? '', remarks: p.remarks ?? '', date_issued: p.date_issued ?? '' });
        setEditing(p);
    };

    const savePrinter = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const payload = { model: form.model, mac_address: form.mac_address || null, serial_number: form.serial_number || null, remarks: form.remarks || null };
            if (editing.id) {
                if (editing.company) payload.date_issued = form.date_issued || null;
                await printersApi.update(editing.id, payload);
                notifySuccess('Printer updated.');
            } else {
                const created = await printersApi.create(payload);
                notifySuccess(`Printer registered as ${created.thermal_id}.`);
            }
            setEditing(null);
            reload();
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    // --- Assign company ----------------------------------------------------
    const [assigningCompany, setAssigningCompany] = useState(null);
    const [companyForm, setCompanyForm] = useState({ company_id: '', date_issued: today(), remarks: '' });
    const pickCompanies = useCompanyOptions(!!assigningCompany);

    const openAssignCompany = (p) => {
        setCompanyForm({ company_id: '', date_issued: today(), remarks: '' });
        setAssigningCompany(p);
    };

    const saveCompany = async (e) => {
        e.preventDefault();
        const p = assigningCompany;
        const newName = pickCompanies.options.find((o) => o.value === companyForm.company_id)?.label ?? 'the selected company';
        if (p.company) {
            const ok = await confirmAction({
                title: 'Move this printer to another company?',
                text: `${p.thermal_id}: ${p.company.name} → ${newName}.${p.bus ? ` It will be taken off bus ${p.bus.bus_number}.` : ''}`,
                confirmText: 'Move printer',
            });
            if (!ok) return;
        }
        setSaving(true);
        try {
            await printersApi.assignCompany(p.id, { company_id: Number(companyForm.company_id), date_issued: companyForm.date_issued || null, remarks: companyForm.remarks || null });
            notifySuccess(`${p.thermal_id} issued to ${newName}.`);
            setAssigningCompany(null);
            reload();
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    // --- Assign bus --------------------------------------------------------
    const [assigningBus, setAssigningBus] = useState(null);
    const [busOptions, setBusOptions] = useState([]);
    const [busesLoading, setBusesLoading] = useState(false);
    const [busForm, setBusForm] = useState({ bus_id: '', remarks: '' });

    const openAssignBus = async (p) => {
        setBusForm({ bus_id: p.bus ? String(p.bus.id) : '', remarks: '' });
        setBusOptions([]);
        setAssigningBus(p);
        setBusesLoading(true);
        try {
            const list = await printersApi.buses(p.company.id);
            setBusOptions(list.map((b) => ({
                value: String(b.id),
                label: `${b.bus_number}${b.plate_number ? ` · ${b.plate_number}` : ''}${b.assignable ? '' : ` (${b.status})`}${p.bus?.id === b.id ? ' — current' : ''}`,
                disabled: !b.assignable && p.bus?.id !== b.id,
            })));
        } catch (err) {
            notifyError(err, 'Could not load this company’s buses.');
        } finally {
            setBusesLoading(false);
        }
    };

    const submitBus = async (p, busIdToSave, remarks) => {
        const newBus = busOptions.find((o) => o.value === String(busIdToSave));
        const ok = await confirmAction({
            title: busIdToSave ? (p.bus ? 'Move printer to another bus?' : 'Assign printer to this bus?') : 'Remove printer from its bus?',
            text: `${p.thermal_id}: ${busLabel(p.bus) ?? 'Not Assigned'} → ${busIdToSave ? newBus?.label.replace(' — current', '') : 'Not Assigned'}`,
            confirmText: busIdToSave ? 'Save assignment' : 'Remove',
            danger: !busIdToSave,
        });
        if (!ok) return;
        setSaving(true);
        try {
            await printersApi.assignBus(p.id, { bus_id: busIdToSave ? Number(busIdToSave) : null, remarks: remarks || null });
            notifySuccess(busIdToSave ? 'Bus assignment saved.' : 'Printer removed from its bus.');
            setAssigningBus(null);
            reload();
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const saveBus = (e) => {
        e.preventDefault();
        submitBus(assigningBus, busForm.bus_id, busForm.remarks);
    };

    // --- Status ------------------------------------------------------------
    const [changingStatus, setChangingStatus] = useState(null);
    const [statusForm, setStatusForm] = useState({ status: '', remarks: '' });

    const openStatus = (p) => { setStatusForm({ status: p.status_options?.[0] ?? '', remarks: '' }); setChangingStatus(p); };

    const saveStatus = async (e) => {
        e.preventDefault();
        const p = changingStatus;
        const ok = await confirmAction({
            title: `Mark ${p.thermal_id} as ${statusForm.status}?`,
            text: STATUS_EFFECT[statusForm.status] ?? '',
            confirmText: 'Change status',
            danger: ['Retired', 'Defective'].includes(statusForm.status),
        });
        if (!ok) return;
        setSaving(true);
        try {
            await printersApi.setStatus(p.id, { status: statusForm.status, remarks: statusForm.remarks || null });
            notifySuccess(`${p.thermal_id} is now ${statusForm.status}.`);
            setChangingStatus(null);
            reload();
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    // --- Details + history -------------------------------------------------
    const [details, setDetails] = useState(null);
    const openDetails = useCallback(async (p) => {
        setDetails({ ...p, history: null });
        try {
            setDetails(await printersApi.get(p.id));
        } catch (err) {
            notifyError(err);
        }
    }, []);

    // -----------------------------------------------------------------------

    const actionsFor = (p) => {
        const items = [{ key: 'view', icon: 'bi-eye', label: 'View details & history', run: () => openDetails(p) }];
        if (!canManage) return items;
        if (p.status !== 'Retired') items.push({ key: 'edit', icon: 'bi-pencil', label: 'Edit printer', run: () => openEdit(p) });
        if (ACCEPTS_COMPANY.includes(p.status)) {
            items.push({ key: 'company', icon: 'bi-buildings', label: p.company ? 'Change company' : 'Assign company', run: () => openAssignCompany(p) });
        }
        if (p.status === 'Assigned' && p.company) {
            items.push({ key: 'bus', icon: 'bi-bus-front', label: p.bus ? 'Change bus' : 'Assign bus', run: () => openAssignBus(p) });
        }
        if (p.bus) {
            items.push({ key: 'unbus', icon: 'bi-x-circle', label: 'Remove from bus', danger: true, run: () => { setBusOptions([]); submitBus(p, null, null); } });
        }
        if (p.status_options?.length) items.push({ key: 'status', icon: 'bi-arrow-repeat', label: 'Update status', run: () => openStatus(p) });
        return items;
    };

    return (
        <>
            <PageHeader
                eyebrow="Platform"
                title="Thermal Printer Management"
                subtitle="Every thermal printer the platform has issued: register units, issue them to companies, and assign each one to a specific bus."
                actions={canManage && (
                    <button className="btn btn-accent" onClick={openNew}>
                        <i className="bi bi-plus-lg me-1" /> Register printer
                    </button>
                )}
            />

            <div className="row g-2 mb-3 align-items-end">
                <div className="col-12 col-lg-4">
                    <div className="input-group">
                        <span className="input-group-text bg-body"><i className="bi bi-search" /></span>
                        <input
                            className="form-control"
                            placeholder="Thermal ID, Device ID, MAC, model, company or bus"
                            aria-label="Search printers"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </div>
                </div>
                <div className="col-6 col-md-3 col-lg-2">
                    <select className="form-select" aria-label="Filter by status" value={status} onChange={(e) => setStatus(e.target.value)}>
                        <option value="">All statuses</option>
                        {PRINTER_STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
                    </select>
                </div>
                <div className="col-6 col-md-3 col-lg-2">
                    <select className="form-select" aria-label="Filter by assignment" value={assignment} onChange={(e) => setAssignment(e.target.value)}>
                        {ASSIGNMENTS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                    </select>
                </div>
                <div className="col-6 col-md-3 col-lg-2">
                    <select
                        className="form-select"
                        aria-label="Filter by company"
                        value={companyId}
                        onChange={(e) => setCompanyId(e.target.value)}
                    >
                        <option value="">All companies</option>
                        {filterCompanies.options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </select>
                </div>
                <div className="col-6 col-md-3 col-lg-2">
                    <select className="form-select" aria-label="Filter by assigned bus" value={busId} disabled={!companyId} onChange={(e) => setBusId(e.target.value)}>
                        <option value="">{companyId ? 'All buses' : 'Pick a company for buses'}</option>
                        {filterBuses.map((b) => <option key={b.id} value={b.id}>{busLabel(b)}</option>)}
                    </select>
                </div>
            </div>
            <div className="d-flex align-items-center mb-2 small text-body-secondary">
                {meta && <span>{meta.total} {meta.total === 1 ? 'printer' : 'printers'}</span>}
                {filtered && <button className="btn btn-link btn-sm ms-2 p-0" onClick={clearFilters}>Clear filters</button>}
            </div>

            <div className="card border-0 shadow-sm">
                <div className="table-responsive">
                    <table className="table table-hover align-middle mb-0">
                        <thead className="table-light">
                            <tr>
                                <th>Thermal ID</th>
                                <th>Device ID</th>
                                <th>MAC address</th>
                                <th>Model</th>
                                <th>Company</th>
                                <th>Assigned bus</th>
                                <th>Status</th>
                                <th>Date issued</th>
                                <th className="text-end"><span className="visually-hidden">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading && <SkeletonTableRows columns={9} />}
                            {!loading && rows.length === 0 && (
                                <tr>
                                    <td colSpan={9} className="tf-empty">
                                        <i className="bi bi-printer d-block fs-3 mb-2" />
                                        {filtered ? 'No printers match these filters.' : 'No thermal printers registered yet.'}
                                    </td>
                                </tr>
                            )}
                            {!loading && rows.map((p) => (
                                <tr key={p.id}>
                                    <td className="text-nowrap"><code className="fw-semibold">{p.thermal_id}</code></td>
                                    <td className="text-nowrap"><code>{p.device_id}</code></td>
                                    <td className="text-nowrap">{p.mac_address ? <code>{p.mac_address}</code> : muted('—')}</td>
                                    <td>{p.model}</td>
                                    <td>{p.company ? p.company.name : notAssigned}</td>
                                    <td className="text-nowrap">{busLabel(p.bus) ?? notAssigned}</td>
                                    <td><PrinterStatusBadge value={p.status} /></td>
                                    <td className="small text-nowrap">{fmtDate(p.date_issued)}</td>
                                    <td className="text-end text-nowrap">
                                        <div className="dropdown d-inline-block">
                                            <button className="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown" aria-expanded="false" aria-label={`Actions for ${p.thermal_id}`}>
                                                <i className="bi bi-three-dots" />
                                            </button>
                                            <ul className="dropdown-menu dropdown-menu-end">
                                                {actionsFor(p).map((a) => (
                                                    <li key={a.key}>
                                                        <button className={`dropdown-item${a.danger ? ' text-danger' : ''}`} onClick={a.run}>
                                                            <i className={`bi ${a.icon} me-2`} />{a.label}
                                                        </button>
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <Pagination meta={meta} page={page} onPage={setPage} />

            {/* Register / edit */}
            <Modal
                open={!!editing}
                title={editing?.id ? `Edit ${editing.thermal_id}` : 'Register thermal printer'}
                onClose={() => setEditing(null)}
                footer={<><button className="btn btn-light" onClick={() => setEditing(null)}>Cancel</button><SaveButton form="tp-form" saving={saving}>{editing?.id ? 'Save' : 'Register'}</SaveButton></>}
            >
                <form id="tp-form" onSubmit={savePrinter} className="vstack gap-3">
                    <div className="row g-2">
                        <div className="col-6">
                            <label className="form-label" htmlFor="tp-form-thermal">Thermal ID</label>
                            <input id="tp-form-thermal" className="form-control font-monospace" readOnly value={editing?.thermal_id ?? 'TFTP-DDMMYY-XXXXXX'} />
                        </div>
                        <div className="col-6">
                            <label className="form-label" htmlFor="tp-form-device">Device ID</label>
                            <input id="tp-form-device" className="form-control font-monospace" readOnly value={editing?.device_id ?? 'TFD-DDMMYY-XXXXXX'} />
                        </div>
                        {!editing?.id && <div className="form-text mt-1">Both IDs are generated by the system when you register the printer, and never change.</div>}
                    </div>
                    <div>
                        <label className="form-label" htmlFor="tp-form-model">Brand & model *</label>
                        <input id="tp-form-model" className="form-control" required maxLength={100} placeholder="e.g. Epson TM-P20" value={form.model ?? ''} onChange={(e) => setForm({ ...form, model: e.target.value })} />
                    </div>
                    <div>
                        <label className="form-label" htmlFor="tp-form-mac">MAC address</label>
                        <input id="tp-form-mac" className="form-control font-monospace" placeholder="00:1A:2B:3C:4D:5E" value={form.mac_address ?? ''} onChange={(e) => setForm({ ...form, mac_address: e.target.value })} />
                        <div className="form-text">Optional. Used to stop the same physical printer being registered twice.</div>
                    </div>
                    <div>
                        <label className="form-label" htmlFor="tp-form-serial">Manufacturer serial no.</label>
                        <input id="tp-form-serial" className="form-control" maxLength={40} value={form.serial_number ?? ''} onChange={(e) => setForm({ ...form, serial_number: e.target.value })} />
                    </div>
                    {editing?.company && (
                        <div>
                            <label className="form-label" htmlFor="tp-form-issued">Date issued to {editing.company.name}</label>
                            <input id="tp-form-issued" type="date" className="form-control" max={today()} value={form.date_issued ?? ''} onChange={(e) => setForm({ ...form, date_issued: e.target.value })} />
                        </div>
                    )}
                    <div>
                        <label className="form-label" htmlFor="tp-form-remarks">Remarks</label>
                        <textarea id="tp-form-remarks" className="form-control" rows={2} maxLength={1000} value={form.remarks ?? ''} onChange={(e) => setForm({ ...form, remarks: e.target.value })} />
                    </div>
                </form>
            </Modal>

            {/* Assign company */}
            <Modal
                open={!!assigningCompany}
                title={assigningCompany?.company ? 'Change company' : 'Assign company'}
                onClose={() => setAssigningCompany(null)}
                footer={<><button className="btn btn-light" onClick={() => setAssigningCompany(null)}>Cancel</button><SaveButton form="tp-company-form" saving={saving} disabled={!companyForm.company_id}>Assign</SaveButton></>}
            >
                {assigningCompany && (
                    <form id="tp-company-form" onSubmit={saveCompany} className="vstack gap-3">
                        <Identity printer={assigningCompany} />
                        {assigningCompany.company && (
                            <div className="alert alert-warning small mb-0">
                                Currently issued to <strong>{assigningCompany.company.name}</strong>
                                {assigningCompany.bus && <> on bus <strong>{assigningCompany.bus.bus_number}</strong>. Moving it takes it off that bus</>}.
                            </div>
                        )}
                        <SearchableSelect
                            id="tp-company"
                            label="Company"
                            required
                            options={pickCompanies.options.filter((o) => o.value !== String(assigningCompany.company?.id))}
                            onSearch={pickCompanies.search}
                            value={companyForm.company_id}
                            onChange={(v) => setCompanyForm({ ...companyForm, company_id: v })}
                            placeholder="Select a company…"
                        />
                        <div>
                            <label className="form-label" htmlFor="tp-company-date">Date issued</label>
                            <input id="tp-company-date" type="date" className="form-control" max={today()} value={companyForm.date_issued} onChange={(e) => setCompanyForm({ ...companyForm, date_issued: e.target.value })} />
                        </div>
                        <div>
                            <label className="form-label" htmlFor="tp-company-remarks">Remarks</label>
                            <textarea id="tp-company-remarks" className="form-control" rows={2} maxLength={1000} value={companyForm.remarks} onChange={(e) => setCompanyForm({ ...companyForm, remarks: e.target.value })} />
                        </div>
                        <div className="form-text mt-0">Issuing a printer doesn’t assign it to a bus. Do that next with <strong>Assign bus</strong>.</div>
                    </form>
                )}
            </Modal>

            {/* Assign bus */}
            <Modal
                open={!!assigningBus}
                title={assigningBus?.bus ? 'Change bus' : 'Assign bus'}
                onClose={() => setAssigningBus(null)}
                footer={(
                    <>
                        {assigningBus?.bus && (
                            <button type="button" className="btn btn-outline-danger me-auto" disabled={saving} onClick={() => submitBus(assigningBus, null, busForm.remarks)}>
                                Remove from bus
                            </button>
                        )}
                        <button className="btn btn-light" onClick={() => setAssigningBus(null)}>Cancel</button>
                        <SaveButton form="tp-bus-form" saving={saving} disabled={!busForm.bus_id || busForm.bus_id === String(assigningBus?.bus?.id ?? '')}>Assign</SaveButton>
                    </>
                )}
            >
                {assigningBus && (
                    <form id="tp-bus-form" onSubmit={saveBus} className="vstack gap-3">
                        <Identity printer={assigningBus} showCompany />
                        <div className="small"><span className="text-body-secondary">Current bus:</span> <strong>{busLabel(assigningBus.bus) ?? 'Not Assigned'}</strong></div>
                        {busesLoading ? (
                            <div className="text-body-secondary small"><span className="spinner-border spinner-border-sm me-2" />Loading {assigningBus.company.name}’s buses…</div>
                        ) : busOptions.length === 0 ? (
                            <div className="alert alert-light small mb-0">{assigningBus.company.name} has no buses yet.</div>
                        ) : (
                            <SearchableSelect
                                id="tp-bus"
                                label={`Bus (${assigningBus.company.name})`}
                                required
                                options={busOptions}
                                value={busForm.bus_id}
                                onChange={(v) => setBusForm({ ...busForm, bus_id: v })}
                                placeholder="Select a bus…"
                            />
                        )}
                        <div>
                            <label className="form-label" htmlFor="tp-bus-remarks">Remarks</label>
                            <textarea id="tp-bus-remarks" className="form-control" rows={2} maxLength={1000} value={busForm.remarks} onChange={(e) => setBusForm({ ...busForm, remarks: e.target.value })} />
                        </div>
                        <div className="form-text mt-0">Only this company’s Active buses can be picked. The assignment date is recorded automatically.</div>
                    </form>
                )}
            </Modal>

            {/* Status */}
            <Modal
                open={!!changingStatus}
                title="Update status"
                onClose={() => setChangingStatus(null)}
                footer={<><button className="btn btn-light" onClick={() => setChangingStatus(null)}>Cancel</button><SaveButton form="tp-status-form" saving={saving} disabled={!statusForm.status}>Change status</SaveButton></>}
            >
                {changingStatus && (
                    <form id="tp-status-form" onSubmit={saveStatus} className="vstack gap-3">
                        <Identity printer={changingStatus} showCompany />
                        <div className="small"><span className="text-body-secondary">Current status:</span> <PrinterStatusBadge value={changingStatus.status} /></div>
                        <div>
                            <label className="form-label" htmlFor="tp-status">New status</label>
                            <select id="tp-status" className="form-select" required value={statusForm.status} onChange={(e) => setStatusForm({ ...statusForm, status: e.target.value })}>
                                {changingStatus.status_options.map((s) => <option key={s} value={s}>{s}</option>)}
                            </select>
                            {statusForm.status && <div className="form-text">{STATUS_EFFECT[statusForm.status]}</div>}
                            {changingStatus.company && !changingStatus.status_options.includes('Retired') && changingStatus.status !== 'Retired' && (
                                <div className="form-text">To retire it or return it to the pool, mark it <strong>Returned</strong> first.</div>
                            )}
                        </div>
                        <div>
                            <label className="form-label" htmlFor="tp-status-remarks">Remarks</label>
                            <textarea id="tp-status-remarks" className="form-control" rows={2} maxLength={1000} placeholder="e.g. paper feed jams" value={statusForm.remarks} onChange={(e) => setStatusForm({ ...statusForm, remarks: e.target.value })} />
                        </div>
                    </form>
                )}
            </Modal>

            {/* Details + history */}
            <Modal open={!!details} title={details?.thermal_id ?? ''} size="lg" onClose={() => setDetails(null)}>
                {details && (
                    <>
                        <dl className="row small mb-3">
                            <dt className="col-sm-3">Thermal ID</dt><dd className="col-sm-3"><code>{details.thermal_id}</code></dd>
                            <dt className="col-sm-3">Device ID</dt><dd className="col-sm-3"><code>{details.device_id}</code></dd>
                            <dt className="col-sm-3">MAC address</dt><dd className="col-sm-3">{details.mac_address ? <code>{details.mac_address}</code> : '—'}</dd>
                            <dt className="col-sm-3">Model</dt><dd className="col-sm-3">{details.model}</dd>
                            <dt className="col-sm-3">Serial no.</dt><dd className="col-sm-3">{details.serial_number || '—'}</dd>
                            <dt className="col-sm-3">Status</dt><dd className="col-sm-3"><PrinterStatusBadge value={details.status} /></dd>
                            <dt className="col-sm-3">Company</dt><dd className="col-sm-3">{details.company?.name ?? 'Not Assigned'}</dd>
                            <dt className="col-sm-3">Assigned bus</dt><dd className="col-sm-3">{busLabel(details.bus) ?? 'Not Assigned'}</dd>
                            <dt className="col-sm-3">Date issued</dt><dd className="col-sm-3">{fmtDate(details.date_issued)}</dd>
                            <dt className="col-sm-3">Registered</dt><dd className="col-sm-3">{fmtDateTime(details.created_at)}</dd>
                            {details.remarks && (<><dt className="col-sm-3">Remarks</dt><dd className="col-sm-9" style={{ whiteSpace: 'pre-line' }}>{details.remarks}</dd></>)}
                        </dl>
                        <h6 className="mb-2">Assignment history</h6>
                        {details.history === null ? (
                            <div className="text-body-secondary small"><span className="spinner-border spinner-border-sm me-2" />Loading…</div>
                        ) : (details.history ?? []).length === 0 ? (
                            <div className="text-body-secondary small">No history yet.</div>
                        ) : (
                            <div className="table-responsive">
                                <table className="table table-sm small align-middle mb-0">
                                    <thead className="table-light">
                                        <tr><th>When</th><th>Change</th><th>Company</th><th>Bus</th><th>Status</th><th>By</th></tr>
                                    </thead>
                                    <tbody>
                                        {details.history.map((h) => (
                                            <tr key={h.id}>
                                                <td className="text-nowrap">{fmtDateTime(h.created_at)}</td>
                                                <td>
                                                    {ACTION_LABELS[h.action] ?? h.action}
                                                    {h.remarks && <div className="text-body-secondary">{h.remarks}</div>}
                                                </td>
                                                <td>{h.from_company === h.to_company ? (h.to_company ?? '—') : `${h.from_company ?? '—'} → ${h.to_company ?? '—'}`}</td>
                                                <td>{h.from_bus === h.to_bus ? (h.to_bus ?? '—') : `${h.from_bus ?? '—'} → ${h.to_bus ?? '—'}`}</td>
                                                <td>{h.from_status === h.to_status ? (h.to_status ?? '—') : `${h.from_status ?? '—'} → ${h.to_status ?? '—'}`}</td>
                                                <td>{h.user_name ?? '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </>
                )}
            </Modal>
        </>
    );
}
