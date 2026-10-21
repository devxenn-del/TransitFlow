import { useEffect, useState } from 'react';

import Modal from '../../components/Modal.jsx';
import { charters } from '../../lib/api.js';
import { notifyError, notifySuccess } from '../../lib/ui.js';
import { toLocalInput } from './charterUi.jsx';

const BLANK = {
    client_name: '', contact_person: '', contact_number: '', contact_email: '', purpose: '',
    pickup_point: '', destination: '', departure_at: '', return_at: '', passenger_count: '',
    agreed_price: '', notes: '', bus_id: '', driver_id: '', conductor_id: '',
};

/**
 * New / edit charter booking. Once both times are set, the bus, driver and
 * conductor pickers come from /company/charters/availability for that
 * window — anyone already booked (or out on a trip right now) is shown but
 * disabled with the reason, so a conflicting crew can't be picked. The
 * server re-checks on save (App\Support\CharterConflicts).
 */
export default function CharterFormModal({ open, booking, onClose, onSaved }) {
    const [form, setForm] = useState(BLANK);
    const [crew, setCrew] = useState(null); // { buses, drivers, conductors } with `busy` reasons
    const [crewLoading, setCrewLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const editing = !!booking?.id;

    useEffect(() => {
        if (!open) return;
        setForm(booking?.id ? {
            ...BLANK,
            ...Object.fromEntries(Object.keys(BLANK).map((k) => [k, booking[k] ?? ''])),
            departure_at: toLocalInput(booking.departure_at),
            return_at: toLocalInput(booking.return_at),
        } : BLANK);
        setCrew(null);
    }, [open, booking]);

    // Re-check who's free whenever the window changes.
    useEffect(() => {
        if (!open || !form.departure_at || !form.return_at || form.return_at <= form.departure_at) {
            setCrew(null);
            return undefined;
        }
        let active = true;
        setCrewLoading(true);
        charters.availability({ departure_at: form.departure_at, return_at: form.return_at, except: booking?.id })
            .then((res) => active && setCrew(res))
            .catch((e) => active && notifyError(e))
            .finally(() => active && setCrewLoading(false));
        return () => { active = false; };
    }, [open, form.departure_at, form.return_at, booking?.id]);

    const set = (key) => (e) => setForm({ ...form, [key]: e.target.value });

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const payload = {
                ...form,
                passenger_count: form.passenger_count === '' ? null : form.passenger_count,
                contact_email: form.contact_email || null,
            };
            const saved = editing ? await charters.update(booking.id, payload) : await charters.create(payload);
            notifySuccess(editing
                ? (booking.status === 'Approved' && saved.status === 'Pending' ? 'Booking updated — it needs approval again.' : 'Booking updated.')
                : `Booking ${saved.reference} created — waiting for approval.`);
            onSaved?.(saved);
        } catch (err) {
            notifyError(err);
        } finally {
            setSaving(false);
        }
    };

    const crewSelect = (key, options, label, render) => (
        <div className="col-md-4">
            <label className="form-label">{label} *</label>
            <select className="form-select" required value={form[key]} onChange={set(key)} disabled={!crew}>
                <option value="">{crew ? `Select ${label.toLowerCase()}` : 'Set the dates first'}</option>
                {(options || []).map((o) => (
                    <option key={o.id} value={o.id} disabled={!!o.busy} title={o.busy || ''}>
                        {render(o)}{o.busy ? ' — not available' : ''}
                    </option>
                ))}
            </select>
            {crew && form[key] && options?.find((o) => String(o.id) === String(form[key]))?.busy && (
                <div className="form-text text-danger">{options.find((o) => String(o.id) === String(form[key])).busy}</div>
            )}
        </div>
    );

    return (
        <Modal
            open={open}
            size="lg"
            title={editing ? `Edit ${booking.reference}` : 'New charter booking'}
            onClose={onClose}
            footer={
                <>
                    <button className="btn btn-light" onClick={onClose}>Cancel</button>
                    <button className="btn btn-primary" form="charter-form" disabled={saving}>
                        {saving && <span className="spinner-border spinner-border-sm me-2" />}{editing ? 'Save' : 'Create booking'}
                    </button>
                </>
            }
        >
            <form id="charter-form" onSubmit={save} className="vstack gap-3">
                <div className="tf-eyebrow">Client</div>
                <div className="row g-2">
                    <div className="col-md-6">
                        <label className="form-label">Client / organization *</label>
                        <input className="form-control" required maxLength={150} value={form.client_name} onChange={set('client_name')} />
                    </div>
                    <div className="col-md-6">
                        <label className="form-label">Contact person</label>
                        <input className="form-control" maxLength={150} value={form.contact_person} onChange={set('contact_person')} />
                    </div>
                    <div className="col-md-6">
                        <label className="form-label">Contact number *</label>
                        <input className="form-control" required maxLength={50} value={form.contact_number} onChange={set('contact_number')} />
                    </div>
                    <div className="col-md-6">
                        <label className="form-label">Email</label>
                        <input type="email" className="form-control" maxLength={150} value={form.contact_email} onChange={set('contact_email')} />
                    </div>
                    <div className="col-12">
                        <label className="form-label">Trip purpose</label>
                        <input className="form-control" maxLength={255} placeholder="e.g. Company outing, pilgrimage, school field trip" value={form.purpose} onChange={set('purpose')} />
                    </div>
                </div>

                <div className="tf-eyebrow mt-2">Trip</div>
                <div className="row g-2">
                    <div className="col-md-6">
                        <label className="form-label">Pickup point *</label>
                        <input className="form-control" required maxLength={255} value={form.pickup_point} onChange={set('pickup_point')} />
                    </div>
                    <div className="col-md-6">
                        <label className="form-label">Destination *</label>
                        <input className="form-control" required maxLength={255} value={form.destination} onChange={set('destination')} />
                    </div>
                    <div className="col-md-4">
                        <label className="form-label">Departure *</label>
                        <input type="datetime-local" className="form-control" required value={form.departure_at} onChange={set('departure_at')} />
                    </div>
                    <div className="col-md-4">
                        <label className="form-label">Estimated return *</label>
                        <input type="datetime-local" className="form-control" required min={form.departure_at || undefined} value={form.return_at} onChange={set('return_at')} />
                    </div>
                    <div className="col-md-4">
                        <label className="form-label">Passengers</label>
                        <input type="number" min="1" className="form-control" value={form.passenger_count} onChange={set('passenger_count')} />
                    </div>
                </div>

                <div className="tf-eyebrow mt-2">
                    Crew {crewLoading && <span className="spinner-border spinner-border-sm ms-1" />}
                </div>
                <div className="row g-2">
                    {crewSelect('bus_id', crew?.buses, 'Bus', (b) => `${b.bus_number}${b.capacity ? ` (${b.capacity} seats)` : ''}`)}
                    {crewSelect('driver_id', crew?.drivers, 'Driver', (d) => d.name)}
                    {crewSelect('conductor_id', crew?.conductors, 'Conductor', (c) => c.name)}
                </div>

                <div className="tf-eyebrow mt-2">Price</div>
                <div className="row g-2">
                    <div className="col-md-4">
                        <label className="form-label">Agreed charter price (₱) *</label>
                        <input type="number" min="0" step="0.01" className="form-control" required value={form.agreed_price} onChange={set('agreed_price')} />
                    </div>
                    <div className="col-md-8">
                        <label className="form-label">Notes</label>
                        <input className="form-control" maxLength={2000} value={form.notes} onChange={set('notes')} />
                    </div>
                </div>
                {editing && booking.status === 'Approved' && (
                    <div className="alert alert-warning small mb-0">
                        Changing the crew or the times sends this booking back for approval.
                    </div>
                )}
            </form>
        </Modal>
    );
}
