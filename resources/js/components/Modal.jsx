import { useEffect } from 'react';

/**
 * Minimal controlled modal — rendered only when `open`, with its own
 * backdrop. Avoids wiring Bootstrap's JS data-api into React state.
 */
export default function Modal({ open, title, onClose, children, footer, size }) {
    useEffect(() => {
        if (!open) return;
        const onKey = (e) => e.key === 'Escape' && onClose?.();
        document.addEventListener('keydown', onKey);
        document.body.classList.add('modal-open');
        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.classList.remove('modal-open');
        };
    }, [open, onClose]);

    if (!open) return null;

    return (
        <>
            <div className="modal-backdrop fade show" onClick={onClose} />
            <div className="modal fade show d-block" tabIndex="-1" role="dialog">
                <div className={`modal-dialog modal-dialog-centered ${size ? `modal-${size}` : ''}`} role="document">
                    <div className="modal-content">
                        <div className="modal-header">
                            <h5 className="modal-title">{title}</h5>
                            <button type="button" className="btn-close" aria-label="Close" onClick={onClose} />
                        </div>
                        <div className="modal-body">{children}</div>
                        {footer && <div className="modal-footer">{footer}</div>}
                    </div>
                </div>
            </div>
        </>
    );
}
