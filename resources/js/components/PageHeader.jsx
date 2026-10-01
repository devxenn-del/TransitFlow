import { useCompanyScope } from '../lib/companyScope.jsx';

/**
 * Inside a company workspace the company header + breadcrumb already say
 * where you are, so a page's own header renders compact (no eyebrow).
 */
export default function PageHeader({ eyebrow, title, subtitle, actions }) {
    const inWorkspace = !!useCompanyScope();
    return (
        <div className={`tf-page-head${inWorkspace ? ' is-compact' : ''}`}>
            <div>
                {eyebrow && !inWorkspace && <span className="tf-eyebrow">{eyebrow}</span>}
                {inWorkspace ? <h2>{title}</h2> : <h1>{title}</h1>}
                {subtitle && <div className="tf-subtitle mt-1">{subtitle}</div>}
            </div>
            {actions && <div className="d-flex gap-2 flex-wrap">{actions}</div>}
        </div>
    );
}
