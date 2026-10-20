import transitFlowLogo from '../../images/transitflow-logo.png';

/**
 * The TransitFlow logo in a circular badge with an animated ring around it —
 * the app's one loading indicator. `fullscreen` covers the viewport (app
 * boot); otherwise it centres itself in whatever holds it.
 */
export default function BrandLoader({ label = 'Loading…', size = 'md', fullscreen = false }) {
    const loader = (
        <div className={`tf-loader tf-loader-${size}`} role="status" aria-live="polite">
            <div className="tf-loader-badge">
                <svg className="tf-loader-ring" viewBox="0 0 100 100" aria-hidden="true">
                    <circle className="tf-loader-track" cx="50" cy="50" r="46" />
                    <circle className="tf-loader-arc" cx="50" cy="50" r="46" />
                </svg>
                <span className="tf-loader-pulse" aria-hidden="true" />
                <img src={transitFlowLogo} alt="" className="tf-loader-logo" />
            </div>
            {label && <div className="tf-loader-label">{label}</div>}
        </div>
    );

    return fullscreen ? <div className="tf-loader-screen">{loader}</div> : loader;
}
