import { useEffect, useRef, useState } from 'react';

import FleetMap from '../../components/FleetMap.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import { live } from '../../lib/api.js';

const POLL_MS = 3000;
const peso = (n) => `₱${Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function ago(iso) {
    if (!iso) return 'no fix';
    const secs = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));
    if (secs < 60) return `${secs}s ago`;
    const mins = Math.round(secs / 60);
    return `${mins}m ago`;
}

function Counter({ label, value, tone = 'secondary' }) {
    return (
        <div className={`card border-0 shadow-sm text-center flex-fill`}>
            <div className="card-body py-3">
                <div className={`fs-3 fw-bold text-${tone}`}>{value}</div>
                <div className="small text-muted text-uppercase">{label}</div>
            </div>
        </div>
    );
}

function LiveTripCard({ t }) {
    const loc = t.location;
    const stale = !loc || loc.is_stale;
    return (
        <div className="card border-0 shadow-sm h-100">
            <div className="card-body">
                <div className="d-flex justify-content-between align-items-start">
                    <div>
                        <div className="fw-bold">{t.bus_number}</div>
                        <div className="small text-muted font-monospace">{t.reference}</div>
                    </div>
                    <span className={`badge ${t.status === 'OnTrip' ? 'text-bg-success' : 'text-bg-warning'}`}>
                        {t.status === 'OnTrip' ? 'On trip' : 'At terminal'}
                    </span>
                </div>

                <div className="small mt-2">{t.origin}{t.destination ? ` → ${t.destination}` : ''}</div>
                <div className="small text-muted">{t.conductor_name ?? '—'}</div>

                <div className="d-flex justify-content-between mt-2 pt-2 border-top small">
                    <span>{t.ticket_count} tickets</span>
                    <span className="fw-semibold">{peso(t.collected)}</span>
                </div>

                <div className="mt-2 small">
                    {loc ? (
                        <span className={stale ? 'text-danger' : 'text-success'}>
                            <i className={`bi ${stale ? 'bi-exclamation-triangle' : 'bi-geo-alt-fill'} me-1`} />
                            {loc.lat.toFixed(5)}, {loc.lng.toFixed(5)} · {ago(loc.recorded_at)}
                            {stale && ' (stale)'}
                        </span>
                    ) : (
                        <span className="text-muted"><i className="bi bi-geo me-1" />no GPS fix yet</span>
                    )}
                </div>
            </div>
        </div>
    );
}

export default function LiveMonitor() {
    const [board, setBoard] = useState(null);
    const [error, setError] = useState(false);
    const timer = useRef(null);

    useEffect(() => {
        let active = true;
        const tick = async () => {
            try {
                const data = await live.board();
                if (active) { setBoard(data); setError(false); }
            } catch {
                if (active) setError(true);
            }
        };
        tick();
        timer.current = setInterval(tick, POLL_MS);
        return () => { active = false; clearInterval(timer.current); };
    }, []);

    const c = board?.counters;

    return (
        <>
            <PageHeader
                eyebrow="Operations"
                title="Live Monitor"
                subtitle={<>Fleet status, refreshed every {POLL_MS / 1000}s{board && <> · as of {new Date(board.generated_at).toLocaleTimeString()}</>}</>}
            />

            {error && (
                <div className="alert alert-warning py-2 small">
                    <i className="bi bi-wifi-off me-1" /> Couldn't reach the server — retrying…
                </div>
            )}

            {!board ? (
                <div className="d-flex justify-content-center py-5"><span className="spinner-border text-primary" /></div>
            ) : (
                <>
                    <div className="d-flex flex-wrap gap-2 mb-4">
                        <Counter label="Live" value={c.live} tone="primary" />
                        <Counter label="On trip" value={c.on_trip} tone="success" />
                        <Counter label="At terminal" value={c.at_terminal} tone="warning" />
                        <Counter label="Arrived today" value={c.arrived_today} tone="secondary" />
                        <Counter label="Stale GPS" value={c.stale} tone={c.stale ? 'danger' : 'secondary'} />
                    </div>

                    <div className="mb-4">
                        <FleetMap trips={board.live_trips} height={380} />
                        {board.counters.stale > 0 && (
                            <div className="small text-danger mt-1">
                                <i className="bi bi-exclamation-triangle me-1" />
                                {board.counters.stale} bus{board.counters.stale === 1 ? '' : 'es'} with a stale or missing GPS fix.
                            </div>
                        )}
                    </div>

                    <h2 className="h6 tf-eyebrow text-uppercase text-muted">Fleet — live trips</h2>
                    {board.live_trips.length === 0 ? (
                        <div className="card border-0 shadow-sm"><div className="card-body text-muted">No buses are out right now.</div></div>
                    ) : (
                        <div className="row g-3 mb-4">
                            {board.live_trips.map((t) => (
                                <div className="col-md-6 col-xl-4" key={t.trip_id}><LiveTripCard t={t} /></div>
                            ))}
                        </div>
                    )}

                    <h2 className="h6 tf-eyebrow text-uppercase text-muted mt-4">Arrived today — awaiting remittance</h2>
                    <div className="card border-0 shadow-sm">
                        <div className="table-responsive">
                            <table className="table table-sm table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Reference</th><th>Bus</th><th>Conductor</th><th>Ended</th>
                                        <th className="text-end">Collected</th><th className="text-end">Remitted</th><th>Stage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {board.arrived_today.length === 0 && (
                                        <tr><td colSpan={7} className="tf-empty">Nothing has come in yet today.</td></tr>
                                    )}
                                    {board.arrived_today.map((t) => (
                                        <tr key={t.trip_id}>
                                            <td className="font-monospace small">{t.reference}</td>
                                            <td>{t.bus_number}</td>
                                            <td className="small">{t.conductor_name ?? '—'}</td>
                                            <td className="small text-muted">{t.ended_at ? new Date(t.ended_at).toLocaleTimeString() : '—'}</td>
                                            <td className="text-end">{peso(t.collected)}</td>
                                            <td className="text-end">{t.remitted_amount != null ? peso(t.remitted_amount) : '—'}</td>
                                            <td>
                                                <span className={`badge text-bg-${t.remittance_stage === 'approved' ? 'success' : t.remittance_stage === 'received' ? 'info' : 'light'}`}>
                                                    {t.remittance_stage}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </>
            )}
        </>
    );
}
