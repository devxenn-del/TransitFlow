const DENOMS = [1000, 500, 200, 100, 50, 20, 10, 5, 1];
const peso = (n) => `₱${Number(n || 0).toLocaleString()}`;

/**
 * Cash denomination entry laid out as  BILLS | QUANTITY | AMOUNT  with a
 * running total row. Controlled: `value` is an object keyed q1000..q1,
 * `onChange(next)` gets the whole updated object back.
 */
export default function DenominationTable({ value, onChange, disabled = false }) {
    const set = (d, v) => onChange({ ...value, [`q${d}`]: v });

    const totalQty = DENOMS.reduce((s, d) => s + (Number(value[`q${d}`]) || 0), 0);
    const totalAmount = DENOMS.reduce((s, d) => s + d * (Number(value[`q${d}`]) || 0), 0);

    return (
        <div className="table-responsive">
            <table className="table table-sm align-middle mb-0 tf-denom-table">
                <thead className="table-light">
                    <tr>
                        <th style={{ width: '34%' }}>BILLS</th>
                        <th style={{ width: '33%' }} className="text-center">QUANTITY</th>
                        <th style={{ width: '33%' }} className="text-end">AMOUNT</th>
                    </tr>
                </thead>
                <tbody>
                    {DENOMS.map((d) => (
                        <tr key={d}>
                            <td className="fw-semibold">{peso(d)}</td>
                            <td>
                                <input
                                    type="number"
                                    min="0"
                                    inputMode="numeric"
                                    className="form-control form-control-sm text-center"
                                    disabled={disabled}
                                    value={value[`q${d}`] ?? ''}
                                    onChange={(e) => set(d, e.target.value)}
                                />
                            </td>
                            <td className="text-end fare-amount">{peso(d * (Number(value[`q${d}`]) || 0))}</td>
                        </tr>
                    ))}
                </tbody>
                <tfoot className="table-light">
                    <tr>
                        <th>Total</th>
                        <th className="text-center">{totalQty}</th>
                        <th className="text-end fare-amount fs-6">{peso(totalAmount)}</th>
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}
