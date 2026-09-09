import { Link } from 'react-router-dom';

export default function NotFound() {
    return (
        <div className="text-center py-5">
            <i className="bi bi-signpost-split display-1 text-body-secondary" />
            <h1 className="h3 mt-3">Page not found</h1>
            <p className="text-body-secondary">The page you are looking for does not exist.</p>
            <Link to="/" className="btn btn-primary mt-2">
                <i className="bi bi-house-door me-1" />
                Back home
            </Link>
        </div>
    );
}
