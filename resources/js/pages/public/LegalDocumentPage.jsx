import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';

import { legalDocuments } from '../../lib/api.js';
import { renderLegalContent } from '../../lib/legalContent.jsx';
import { SkeletonText } from '../../components/Skeleton.jsx';

/**
 * Public, unauthenticated Privacy Policy / Terms of Use page — linked from
 * the Login screen and required for Play Store publication. Fetches
 * whichever document is currently active from GET /api/meta/legal.
 */
export default function LegalDocumentPage({ type }) {
    const [doc, setDoc] = useState(undefined); // undefined = loading, null = not published yet

    useEffect(() => {
        legalDocuments.activePublic()
            .then((data) => setDoc(data[type] ?? null))
            .catch(() => setDoc(null));
    }, [type]);

    return (
        <div className="tf-login">
            <div className="container py-5">
                <div className="row justify-content-center">
                    <div className="col-lg-9 col-xl-8">
                        <div className="card border-0 shadow-sm p-4 p-lg-5">
                            <Link to="/login" className="small text-decoration-none d-inline-flex align-items-center mb-3">
                                <i className="bi bi-arrow-left me-1" /> Back to sign in
                            </Link>

                            {doc === undefined && (
                                <div className="py-3"><SkeletonText lines={10} /></div>
                            )}

                            {doc === null && (
                                <p className="text-body-secondary">This document has not been published yet.</p>
                            )}

                            {doc && (
                                <>
                                    <h1 className="h3 fw-bold mb-1">{doc.title}</h1>
                                    <p className="small text-body-secondary mb-4">
                                        Version {doc.version} · Effective {doc.effective_date}
                                    </p>
                                    <div>{renderLegalContent(doc.content)}</div>
                                </>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
