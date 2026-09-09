import 'leaflet/dist/leaflet.css';

import L from 'leaflet';
import { useEffect, useRef } from 'react';

// Cavite service area — the map opens here when no bus has a fix yet.
const DEFAULT_CENTER = [14.32, 120.94];
const DEFAULT_ZOOM = 11;

function pinIcon(stale) {
    const color = stale ? '#dc3545' : '#198754';
    return L.divIcon({
        className: 'tf-bus-pin',
        html: `<span style="
            display:flex;align-items:center;justify-content:center;
            width:26px;height:26px;border-radius:50% 50% 50% 0;
            background:${color};transform:rotate(-45deg);
            border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4);">
            <i class="bi bi-bus-front" style="transform:rotate(45deg);color:#fff;font-size:12px;"></i>
        </span>`,
        iconSize: [26, 26],
        iconAnchor: [13, 26],
        popupAnchor: [0, -24],
    });
}

const peso = (n) => `₱${Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/**
 * Live fleet map. `trips` is the `live_trips` array from `GET /api/company/live`;
 * a marker is drawn for every trip that has a GPS fix, red once the fix goes
 * stale. Tiles come from OpenStreetMap.
 */
export default function FleetMap({ trips = [], height = 360 }) {
    const el = useRef(null);
    const map = useRef(null);
    const layer = useRef(null);

    // Create the map once.
    useEffect(() => {
        if (map.current || !el.current) return;
        map.current = L.map(el.current, { scrollWheelZoom: false }).setView(DEFAULT_CENTER, DEFAULT_ZOOM);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors',
            maxZoom: 19,
        }).addTo(map.current);
        layer.current = L.layerGroup().addTo(map.current);
        // The container is often sized after mount (flex/grid) — nudge Leaflet.
        setTimeout(() => map.current?.invalidateSize(), 150);
        return () => { map.current?.remove(); map.current = null; };
    }, []);

    // Re-draw markers whenever the trips change.
    useEffect(() => {
        if (!map.current || !layer.current) return;
        layer.current.clearLayers();

        const located = trips.filter((t) => t.location);
        const points = [];

        located.forEach((t) => {
            const { lat, lng, is_stale, recorded_at } = t.location;
            points.push([lat, lng]);
            L.marker([lat, lng], { icon: pinIcon(is_stale) })
                .bindPopup(`
                    <div style="min-width:170px">
                        <div style="font-weight:700">${t.bus_number ?? '—'}</div>
                        <div style="font-size:12px;color:#555">${t.origin ?? ''}${t.destination ? ' → ' + t.destination : ''}</div>
                        <div style="font-size:12px;color:#555">${t.conductor_name ?? ''}</div>
                        <hr style="margin:6px 0">
                        <div style="font-size:12px">${t.ticket_count ?? 0} tickets · <b>${peso(t.collected)}</b></div>
                        <div style="font-size:11px;color:${is_stale ? '#dc3545' : '#198754'}">
                            ${is_stale ? 'stale fix' : 'live'} · ${recorded_at ? new Date(recorded_at).toLocaleTimeString() : '—'}
                        </div>
                    </div>
                `)
                .addTo(layer.current);
        });

        if (points.length === 1) {
            map.current.setView(points[0], 14);
        } else if (points.length > 1) {
            map.current.fitBounds(L.latLngBounds(points).pad(0.2));
        }
    }, [trips]);

    return (
        <div
            ref={el}
            style={{ height, width: '100%', borderRadius: 8, overflow: 'hidden', zIndex: 0 }}
            className="border shadow-sm"
        />
    );
}
