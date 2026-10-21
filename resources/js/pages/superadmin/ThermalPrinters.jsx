import { useCallback, useEffect, useRef, useState } from 'react';

import { useAuth } from '../../auth/AuthContext.jsx';
import Modal from '../../components/Modal.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Pagination from '../../components/Pagination.jsx';
import PrinterStatusBadge, { PRINTER_STATUSES } from '../../components/PrinterStatusBadge.jsx';
import SearchableSelect from '../../components/SearchableSelect.jsx';
import { SkeletonTableRows } from '../../components/Skeleton.jsx';
import { companies, platformThermalPrinters as printersApi } from '../../lib/api.js';
import { openPrinterLabels } from '../../lib/printerLabels.js';
import { useList } from '../../lib/useList.js';
import { confirmAction, notifyError, notifySuccess } from '../../lib/ui.js';

const ASSIGNMENTS = [
    ['', 'All assignments'],
    ['assigned', 'Issued to a company'],
    ['on_bus', 'On a bus'],
    ['no_bus', 'Issued, no bus yet'],
    ['unassigned', 'Not issued'],
];

/** Most labels one print job may hold — ThermalPrinterController::MAX_LABELS. */
const MAX_LABELS = 500;

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

/** One "label — value" row in the details drawer. */
function DrawerRow({ label, children }) {
    return (
        <div className="tf-drawer-row">
            <dt>{label}</dt>
            <dd>{children || <span className="text-body-tertiary">—</span>}</dd>
        </div>
    );
}

/** "A → B" for whichever of company / bus / status a history entry changed. */
function describeChange(h) {
    const parts = [];
    const diff = (from, to, noun) => {
        if (from !== to) parts.push(`${noun}: ${from ?? 'none'} → ${to ?? 'none'}`);
    };
    diff(h.from_company, h.to_company, 'Company');
    diff(h.from_bus, h.to_bus, 'Bus');
    diff(h.from_status, h.to_status, 'Status');
    return parts.join(' · ');
}

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
            {showCompany && <div className="col-12"><span className="text-body-secondary">Assigned company</span><div className="fw-semibold">{printer.company?.name ?? 'Not Assigned'}</div>{printer.company && <code className="small">{printer.company.code}</code>}</div>}
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
    const currentFilters = { q, status, assignment, company_id: companyId, bus_id: busId };

    // --- Selection (label printing) ------------------------------------------
    // `selected` survives paging and filtering. `allMatching` means "every
    // printer matching the current filters", so it resets when they change.
    const [selected, setSelected] = useState(() => new Set());
    const [allMatching, setAllMatching] = useState(false);
    const headerCheckbox = useRef(null);

    useEffect(() => setAllMatching(false), [q, status, assignment, companyId, busId]);

    const pageIds = rows.map((p) => p.id);
    const pageSelectedCount = allMatching ? pageIds.length : pageIds.filter((id) => selected.has(id)).length;
    const allOnPage = pageIds.length > 0 && pageSelectedCount === pageIds.length;
    const selectionCount = allMatching ? (meta?.total ?? 0) : selected.size;

    useEffect(() => {
        if (headerCheckbox.current) headerCheckbox.current.indeterminate = pageSelectedCount > 0 && !allOnPage;
    }, [pageSelectedCount, allOnPage]);

    const toggleOne = (id) => {
        if (allMatching) {
            // Leaving "all matching": keep this page minus the one unticked.
            setAllMatching(false);
            setSelected(new Set(pageIds.filter((pid) => pid !== id)));
            return;
        }
        setSelected((prev) => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    };

    const togglePage = () => {
        if (allMatching) { setAllMatching(false); setSelected(new Set()); return; }
        setSelected((prev) => {
            const next = new Set(prev);
            pageIds.forEach((id) => (allOnPage ? next.delete(id) : next.add(id)));
            return next;
        });
    };

    const clearSelection = () => { setAllMatching(false); setSelected(new Set()); };

    const printSelected = () => {
        if (selectionCount > MAX_LABELS) {
            notifyError(null, `Up to ${MAX_LABELS} labels can be printed at once. Narrow the filters and print in batches.`);
            return;
        }
        if (allMatching) openPrinterLabels({ filters: currentFilters });
        else openPrinterLabels({ ids: [...selected] });
    };

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
    const detailsId = details?.id;
    const openDetails = useCallback(async (p) => {
        setDetails({ ...p, history: null });
        try {
            setDetails(await printersApi.get(p.id));
        } catch (err) {
            notifyError(err);
        }
    }, []);

    useEffect(() => {
        if (!detailsId) return undefined;
        const onKey = (e) => e.key === 'Escape' && !document.querySelector('.modal.show') && setDetails(null);
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [detailsId]);

    // --- Summary cards -------------------------------------------------------
    // Reloaded with the list, so they and an open drawer reflect every change.
    const [summary, setSummary] = useState(null);
    useEffect(() => {
        if (loading) return;
        printersApi.summary().then(setSummary).catch(() => {});
        if (detailsId) printersApi.get(detailsId).then(setDetails).catch(() => {});
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [loading]);

    // -----------------------------------------------------------------------

    const actionsFor = (p) => {
        const items = [
            { key: 'view', icon: 'bi-eye', label: 'View details & history', run: () => openDetails(p) },
            { key: 'label', icon: 'bi-qr-code', label: 'Print label', run: () => openPrinterLabels({ ids: [p.id] }) },
        ];
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

    const kpis = [
        { key: 'all', icon: 'bi-printer', label: 'All printers', hint: 'Whole inventory', value: summary?.total, filter: {} },
        { key: 'available', icon: 'bi-box-seam', label: 'Available', hint: 'Ready to issue', value: summary?.by_status.Available, filter: { status: 'Available' } },
        { key: 'on_bus', icon: 'bi-bus-front', label: 'On a bus', hint: 'In service', value: summary?.on_bus, filter: { assignment: 'on_bus' } },
        { key: 'no_bus', icon: 'bi-hourglass-split', label: 'Awaiting bus', hint: 'Needs a bus', value: summary?.awaiting_bus, filter: { assignment: 'no_bus' }, warn: summary?.awaiting_bus > 0 },
        { key: 'maintenance', icon: 'bi-tools', label: 'Maintenance', hint: 'Being repaired', value: summary?.by_status['Under Maintenance'], filter: { status: 'Under Maintenance' } },
        { key: 'defective', icon: 'bi-exclamation-triangle', label: 'Defective', hint: 'Reported faulty', value: summary?.by_status.Defective, filter: { status: 'Defective' }, warn: summary?.by_status.Defective > 0 },
    ];
    const activeKpi = kpis.find((k) => (k.filter.status ?? '') === status && (k.filter.assignment ?? '') === assignment && !q && !companyId)?.key;
    const applyKpi = (k) => {
        setSearch('');
        setCompanyId('');
        setStatus(k.filter.status ?? '');
        setAssignment(k.filter.assignment ?? '');
    };

    const stop = (e) => e.stopPropagation();
    const inventoryEmpty = !loading && !filtered && rows.length === 0;

    return (
        <>
            <PageHeader
                eyebrow="Platform"
                title="Thermal Printer Management"
                subtitle="Register thermal printers, issue them to companies, assign each one to a bus, and print asset labels."
                actions={canManage && (
                    <button className="btn btn-accent" onClick={openNew}>
                        <i className="bi bi-plus-lg me-1" /> Register printer
                    </button>
                )}
            />

            {/* Inventory at a glance — each card filters the list */}
            <div className="row g-3 mb-4">
                {kpis.map((k) => (
                    <div key={k.key} className="col-6 col-md-4 col-xl-2">
                        <button
                            type="button"
                            className={`tf-stat tf-stat--filter w-100 text-start${activeKpi === k.key ? ' is-active' : ''}${k.warn ? ' is-warn' : ''}`}
                            aria-pressed={activeKpi === k.key}
                            onClick={() => applyKpi(k)}
                        >
                            <div className="tf-stat-icon"><i className={`bi ${k.icon}`} /></div>
                            <div className="min-w-0">
                                <div className="tf-stat-value">{summary ? (k.value ?? 0).toLocaleString() : '—'}</div>
                                <div className="tf-stat-label text-truncate">{k.label}</div>
                                {k.hint && <div className="tf-stat-hint text-truncate">{k.hint}</div>}
                            </div>
                        </button>
                    </div>
                ))}
            </div>

            <div className="card tf-inventory-card">
                {/* Toolbar */}
                <div className="tf-inventory-toolbar">
                    <div className="tf-inventory-search">
                        <i className="bi bi-search" aria-hidden="true" />
                        <input
                            type="search"
                            className="form-control"
                            placeholder="Search printers…" title="Search by Device ID, Thermal ID, MAC, model, company or bus"
                            aria-label="Search printers"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </div>
                    <select className="form-select" aria-label="Filter by status" value={status} onChange={(e) => setStatus(e.target.value)}>
                        <option value="">All statuses</option>
                        {PRINTER_STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
                    </select>
                    <select className="form-select" aria-label="Filter by assignment" value={assignment} onChange={(e) => setAssignment(e.target.value)}>
                        {ASSIGNMENTS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                    </select>
                    <select className="form-select" aria-label="Filter by company" value={companyId} onChange={(e) => setCompanyId(e.target.value)}>
                        <option value="">All companies</option>
                        {filterCompanies.options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </select>
                    <select className="form-select" aria-label="Filter by assigned bus" value={busId} disabled={!companyId} onChange={(e) => setBusId(e.target.value)}>
                        <option value="">All buses</option>
                        {filterBuses.map((b) => <option key={b.id} value={b.id}>{busLabel(b)}</option>)}
                    </select>
                    {filtered && (
                        <button type="button" className="btn btn-link text-decoration-none px-1" onClick={clearFilters}>
                            <i className="bi bi-x-circle me-1" />Reset
                        </button>
                    )}
                </div>

                <div className="table-responsive">
                    <table className="table table-hover align-middle mb-0 tf-inventory-table">
                        <thead>
                            <tr>
                                <th className="tf-check-col">
                                    <input
                                        ref={headerCheckbox}
                                        type="checkbox"
                                        className="form-check-input"
                                        aria-label="Select all printers on this page"
                                        checked={allOnPage}
                                        disabled={loading || pageIds.length === 0}
                                        onChange={togglePage}
                                    />
                                </th>
                                <th>Device ID</th>
                                <th>Model</th>
                                <th>Company Code</th>
                                <th>Assigned bus</th>
                                <th>Status</th>
                                <th>Date issued</th>
                                <th className="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading && <SkeletonTableRows columns={8} />}
                            {!loading && rows.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="tf-empty">
                                        {inventoryEmpty ? (
                                            <div className="tf-inventory-empty">
                                                <span className="tf-inventory-empty__icon"><i className="bi bi-printer" /></span>
                                                <div className="fw-semibold text-body">No thermal printers yet</div>
                                                <div className="small mb-3">Register a printer to generate its Thermal ID and Device ID, then issue it to a company.</div>
                                                {canManage && <button type="button" className="btn btn-accent btn-sm" onClick={openNew}><i className="bi bi-plus-lg me-1" />Register printer</button>}
                                            </div>
                                        ) : (
                                            <div className="tf-inventory-empty">
                                                <span className="tf-inventory-empty__icon"><i className="bi bi-funnel" /></span>
                                                <div className="fw-semibold text-body">No printers match these filters</div>
                                                <button type="button" className="btn btn-link btn-sm" onClick={clearFilters}>Reset filters</button>
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            )}
                            {!loading && rows.map((p) => {
                                const isSelected = allMatching || selected.has(p.id);
                                return (
                                    <tr
                                        key={p.id}
                                        className={`tf-row-link${isSelected ? ' is-selected' : ''}`}
                                        tabIndex={0}
                                        aria-label={`Open ${p.device_id}`}
                                        onClick={() => openDetails(p)}
                                        onKeyDown={(e) => e.key === 'Enter' && e.target === e.currentTarget && openDetails(p)}
                                    >
                                        <td className="tf-check-col" onClick={stop} onKeyDown={stop}>
                                            <input
                                                type="checkbox"
                                                className="form-check-input"
                                                aria-label={`Select ${p.device_id}`}
                                                checked={isSelected}
                                                onChange={() => toggleOne(p.id)}
                                            />
                                        </td>
                                        <td className="text-nowrap">
                                            <code
                                                className="tf-hint tf-device-id"
                                                title={`Thermal ID: ${p.thermal_id}\nMAC address: ${p.mac_address ?? 'Not registered'}`}
                                                aria-label={`${p.device_id}. Thermal ID ${p.thermal_id}. MAC address ${p.mac_address ?? 'not registered'}`}
                                            >
                                                {p.device_id}
                                            </code>
                                            {!p.mac_address && <i className="bi bi-exclamation-circle text-warning ms-2" title="No MAC address — the label can't carry a QR code" />}
                                        </td>
                                        <td>{p.model}</td>
                                        <td className="text-nowrap">
                                            {p.company ? (
                                                <span className="tf-code-chip tf-hint" title={p.company.name} aria-label={`${p.company.code}: ${p.company.name}`}>{p.company.code}</span>
                                            ) : notAssigned}
                                        </td>
                                        <td className="text-nowrap">
                                            {p.bus ? (
                                                <span className="d-inline-flex align-items-center gap-2">
                                                    <i className="bi bi-bus-front text-body-secondary" aria-hidden="true" />
                                                    <span>
                                                        <span className="fw-semibold">{p.bus.bus_number}</span>
                                                        {p.bus.plate_number && <span className="d-block small text-body-secondary">{p.bus.plate_number}</span>}
                                                    </span>
                                                </span>
                                            ) : notAssigned}
                                        </td>
                                        <td><PrinterStatusBadge value={p.status} /></td>
                                        <td className="small text-nowrap">{fmtDate(p.date_issued)}</td>
                                        <td className="text-end text-nowrap" onClick={stop} onKeyDown={stop}>
                                            <div className="btn-group btn-group-sm tf-row-actions" role="group" aria-label={`Actions for ${p.device_id}`}>
                                                {actionsFor(p).map((a) => (
                                                    <button
                                                        key={a.key}
                                                        type="button"
                                                        className={`btn ${a.danger ? 'btn-outline-danger' : 'btn-outline-secondary'}`}
                                                        title={a.label}
                                                        aria-label={`${a.label} — ${p.device_id}`}
                                                        onClick={a.run}
                                                    >
                                                        <i className={`bi ${a.icon}`} />
                                                    </button>
                                                ))}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <div className="tf-inventory-foot">
                    <span className="small text-body-secondary">
                        {meta?.total ? `Showing ${meta.from}–${meta.to} of ${meta.total.toLocaleString()} ${meta.total === 1 ? 'printer' : 'printers'}` : ' '}
                    </span>
                    <Pagination meta={meta} page={page} onPage={setPage} />
                </div>
            </div>

            {/* Bulk actions for the ticked printers */}
            {selectionCount > 0 && (
                <div className="tf-bulkbar" role="region" aria-label="Selected printers">
                    <span className="tf-bulkbar__count">{selectionCount.toLocaleString()}</span>
                    <span>
                        {selectionCount === 1 ? 'printer' : 'printers'} selected
                        {allMatching && <span className="opacity-75">{filtered ? ' · all matching the filters' : ' · whole inventory'}</span>}
                    </span>
                    {!allMatching && allOnPage && meta && meta.total > selected.size && (
                        <button type="button" className="btn btn-link btn-sm tf-bulkbar__link" onClick={() => setAllMatching(true)}>
                            Select all {meta.total.toLocaleString()}
                        </button>
                    )}
                    <span className="ms-auto d-flex gap-2">
                        <button type="button" className="btn btn-sm btn-accent" onClick={printSelected}>
                            <i className="bi bi-qr-code me-1" />Print {selectionCount === 1 ? 'label' : 'labels'}
                        </button>
                        <button type="button" className="btn btn-sm btn-outline-light" onClick={clearSelection}>Clear</button>
                    </span>
                </div>
            )}

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
            {details && (
                <>
                    <div className="tf-drawer-backdrop" onClick={() => setDetails(null)} />
                    <aside className="tf-drawer tf-printer-drawer" role="dialog" aria-modal="true" aria-label={`Printer ${details.device_id}`}>
                        <div className="tf-drawer-head">
                            <span className="tf-printer-tile"><i className="bi bi-printer" /></span>
                            <div className="flex-grow-1 min-w-0">
                                <h2 className="tf-drawer-title font-monospace">{details.device_id}</h2>
                                <div className="small text-body-secondary font-monospace">{details.thermal_id}</div>
                                <div className="mt-2"><PrinterStatusBadge value={details.status} /></div>
                            </div>
                            <button type="button" className="btn-close" aria-label="Close" onClick={() => setDetails(null)} />
                        </div>

                        <div className="tf-drawer-body">
                            {canManage && (
                                <div className="tf-drawer-actions mb-4">
                                    {actionsFor(details).filter((a) => !['view', 'label'].includes(a.key)).map((a) => (
                                        <button key={a.key} type="button" className={`btn btn-sm ${a.danger ? 'btn-outline-danger' : 'btn-outline-secondary'}`} onClick={a.run}>
                                            <i className={`bi ${a.icon} me-1`} />{a.label}
                                        </button>
                                    ))}
                                </div>
                            )}

                            <div className="tf-drawer-label">Identification</div>
                            <dl className="tf-drawer-list mb-4">
                                <DrawerRow label="Thermal ID"><code>{details.thermal_id}</code></DrawerRow>
                                <DrawerRow label="Device ID"><code>{details.device_id}</code></DrawerRow>
                                <DrawerRow label="MAC address">
                                    {details.mac_address ? <code>{details.mac_address}</code> : <span className="text-warning-emphasis"><i className="bi bi-exclamation-circle me-1" />Not registered — the label can’t carry a QR code</span>}
                                </DrawerRow>
                                <DrawerRow label="Model">{details.model}</DrawerRow>
                                <DrawerRow label="Serial no.">{details.serial_number}</DrawerRow>
                                <DrawerRow label="Registered">{fmtDateTime(details.created_at)}</DrawerRow>
                            </dl>

                            <div className="tf-drawer-label">Assignment</div>
                            <dl className="tf-drawer-list mb-4">
                                <DrawerRow label="Company">
                                    {details.company ? <>{details.company.name} <span className="tf-code-chip ms-1">{details.company.code}</span></> : 'Not Assigned'}
                                </DrawerRow>
                                <DrawerRow label="Assigned bus">{busLabel(details.bus) ?? 'Not Assigned'}</DrawerRow>
                                <DrawerRow label="Date issued">{details.date_issued ? fmtDate(details.date_issued) : null}</DrawerRow>
                            </dl>

                            {details.remarks && (
                                <div className="mb-4">
                                    <div className="tf-drawer-label">Remarks</div>
                                    <p className="small mb-0" style={{ whiteSpace: 'pre-line' }}>{details.remarks}</p>
                                </div>
                            )}

                            <div className="tf-drawer-label">History</div>
                            {details.history === null ? (
                                <div className="text-body-secondary small"><span className="spinner-border spinner-border-sm me-2" />Loading…</div>
                            ) : (details.history ?? []).length === 0 ? (
                                <div className="text-body-secondary small">No history yet.</div>
                            ) : (
                                <ol className="tf-timeline">
                                    {details.history.map((h) => (
                                        <li key={h.id} className="tf-timeline__item">
                                            <div className="tf-timeline__title">{ACTION_LABELS[h.action] ?? h.action}</div>
                                            <div className="tf-timeline__change">{describeChange(h)}</div>
                                            {h.remarks && <div className="tf-timeline__remarks">“{h.remarks}”</div>}
                                            <div className="tf-timeline__meta">{fmtDateTime(h.created_at)}{h.user_name && ` · ${h.user_name}`}</div>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </div>

                        <div className="tf-drawer-foot">
                            <button type="button" className="btn btn-primary" onClick={() => openPrinterLabels({ ids: [details.id] })}>
                                <i className="bi bi-qr-code me-1" />Print label
                            </button>
                            <button type="button" className="btn btn-light ms-auto" onClick={() => setDetails(null)}>Close</button>
                        </div>
                    </aside>
                </>
            )}
        </>
    );
}
