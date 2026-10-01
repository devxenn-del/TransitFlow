import { getCompanyScope } from './axios.js';

/**
 * Open a thermal receipt in a new tab. `ReceiptView` reads these params,
 * fetches the DTO and auto-prints — the same "directly print" behaviour as
 * BITS `receipt/conductor/*.php`.
 *
 * @param {{src: 'conductor'|'monitor'|'ticket'|'dispatch'|'shift', kind?: string, id?: number|string, qty?: number, date?: string}} params
 */
export function openReceipt(params) {
    // Carry the open company workspace along — the new tab starts with no scope.
    const withCompany = { company: getCompanyScope(), ...params };
    const qs = new URLSearchParams(
        Object.fromEntries(Object.entries(withCompany).filter(([, v]) => v != null && v !== '')),
    ).toString();
    window.open(`/receipt?${qs}`, '_blank', 'noopener');
}
