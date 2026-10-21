/**
 * Open the thermal-printer asset labels in a new tab (pages/PrinterLabels.jsx),
 * which loads them and prints one 58 × 40 mm label per printer.
 *
 * @param {{ids?: Array<number|string>, filters?: Record<string, string|undefined>}} selection
 *   either specific printer ids, or `filters` to print every printer matching them.
 */
export function openPrinterLabels({ ids, filters } = {}) {
    const params = ids?.length
        ? { ids: ids.join(',') }
        : { all: '1', ...Object.fromEntries(Object.entries(filters ?? {}).filter(([, v]) => v != null && v !== '')) };

    window.open(`/thermal-printer-labels?${new URLSearchParams(params).toString()}`, '_blank', 'noopener');
}
