/**
 * Open a thermal receipt in a new tab. `ReceiptView` reads these params,
 * fetches the DTO and auto-prints — the same "directly print" behaviour as
 * BITS `receipt/conductor/*.php`.
 *
 * @param {{src: 'conductor'|'company'|'ticket'|'dispatch'|'shift', kind?: string, id?: number|string, qty?: number, date?: string}} params
 */
export function openReceipt(params) {
    const qs = new URLSearchParams(
        Object.fromEntries(Object.entries(params).filter(([, v]) => v != null && v !== '')),
    ).toString();
    window.open(`/receipt?${qs}`, '_blank', 'noopener');
}
