import { useMemo, useState } from 'react';

/**
 * A native <select> with a type-to-filter box above it — keyboard and
 * screen-reader friendly without a dropdown library. The selected option
 * always stays in the list, even when the filter would hide it.
 *
 * options: [{ value, label, disabled? }]. `onSearch(text)`, when given,
 * is called as the user types (for server-side lookups) instead of
 * filtering `options` locally.
 */
export default function SearchableSelect({ id, label, options, value, onChange, placeholder = 'Select…', onSearch, required, disabled }) {
    const [text, setText] = useState('');

    const visible = useMemo(() => {
        if (onSearch) return options;
        const needle = text.trim().toLowerCase();
        if (!needle) return options;
        return options.filter((o) => String(o.value) === String(value) || o.label.toLowerCase().includes(needle));
    }, [options, text, value, onSearch]);

    const search = (next) => {
        setText(next);
        onSearch?.(next);
    };

    return (
        <div>
            {label && <label className="form-label" htmlFor={id}>{label}{required && ' *'}</label>}
            <div className="input-group input-group-sm mb-1">
                <span className="input-group-text bg-body"><i className="bi bi-search" aria-hidden="true" /></span>
                <input
                    type="search"
                    className="form-control"
                    placeholder="Type to filter…"
                    aria-label={`Filter ${label ?? 'options'}`}
                    aria-controls={id}
                    value={text}
                    disabled={disabled}
                    onChange={(e) => search(e.target.value)}
                />
            </div>
            <select id={id} className="form-select" value={value ?? ''} required={required} disabled={disabled} onChange={(e) => onChange(e.target.value)}>
                <option value="">{placeholder}</option>
                {visible.map((o) => (
                    <option key={o.value} value={o.value} disabled={o.disabled}>{o.label}</option>
                ))}
            </select>
            {visible.length === 0 && text && <div className="form-text">No matches for “{text}”.</div>}
        </div>
    );
}
