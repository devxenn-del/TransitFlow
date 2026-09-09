export default function PageHeader({ eyebrow, title, subtitle, actions }) {
    return (
        <div className="tf-page-head">
            <div>
                {eyebrow && <span className="tf-eyebrow">{eyebrow}</span>}
                <h1>{title}</h1>
                {subtitle && <div className="tf-subtitle mt-1">{subtitle}</div>}
            </div>
            {actions && <div className="d-flex gap-2 flex-wrap">{actions}</div>}
        </div>
    );
}
