import axios from 'axios';

/**
 * Shared Axios instance for the TransitFlow SPA.
 *
 * Token-based auth: the bearer token from POST /api/auth/login is kept in
 * localStorage and attached to every request. A 401 anywhere clears it and
 * bounces the user to the login screen.
 */
const TOKEN_KEY = 'transitflow_token';

export function getToken() {
    try {
        return localStorage.getItem(TOKEN_KEY);
    } catch {
        return null;
    }
}

export function setToken(token) {
    try {
        if (token) {
            localStorage.setItem(TOKEN_KEY, token);
        } else {
            localStorage.removeItem(TOKEN_KEY);
        }
    } catch {
        /* private mode — token just won't persist */
    }
}

const api = axios.create({
    baseURL: '/api',
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

/**
 * The company workspace currently open (`/companies/:id`), or null. Sent as
 * `X-Company-Id` so the shared `company/*` endpoints act on that company:
 * a Super Admin scopes into it; for anyone else the server refuses (403) any
 * company but their own. Set by CompanyWorkspace, cleared when it unmounts.
 */
let companyScope = null;

export function setCompanyScope(companyId) {
    companyScope = companyId ? String(companyId) : null;
}

export function getCompanyScope() {
    return companyScope;
}

api.interceptors.request.use((config) => {
    const token = getToken();
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    if (companyScope && config.headers['X-Company-Id'] === undefined) {
        config.headers['X-Company-Id'] = companyScope;
    }
    return config;
});

let onUnauthorized = null;
export function setUnauthorizedHandler(fn) {
    onUnauthorized = fn;
}

api.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error.response?.status;
        const url = error.config?.url ?? '';
        // Don't hijack the login request's own 401/422.
        if (status === 401 && !url.includes('/auth/login')) {
            setToken(null);
            onUnauthorized?.();
        }
        return Promise.reject(error);
    },
);

/** Pull a human-readable message out of a Laravel error response. */
export function errorMessage(error, fallback = 'Something went wrong.') {
    const data = error?.response?.data;
    if (data?.errors) {
        return Object.values(data.errors).flat().join(' ');
    }
    return data?.message || error?.message || fallback;
}

export default api;
