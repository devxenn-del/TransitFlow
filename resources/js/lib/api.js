import api from './axios.js';

/** Grouped API calls, so components don't hand-write URLs. */

export const auth = {
    login: (payload) => api.post('/auth/login', payload).then((r) => r.data),
    logout: () => api.post('/auth/logout').then((r) => r.data),
    // { data: user, driver } — driver is whichever one was verified at
    // sign-in (see AuthController::me()), resolved fresh from the current
    // token every call, not just at login.
    me: () => api.get('/auth/me').then((r) => r.data),
    changePassword: (payload) => api.post('/auth/password', payload).then((r) => r.data),
    updateProfile: (payload) => api.put('/auth/profile', payload).then((r) => r.data.data),
    setPin: (payload) => api.put('/auth/pin', payload).then((r) => r.data),
};

export const roles = {
    list: () => api.get('/roles').then((r) => r.data.data),
};

export const companyRoles = {
    list: () => api.get('/company/roles').then((r) => r.data),
    create: (payload) => api.post('/company/roles', payload).then((r) => r.data.data),
    update: (id, payload) => api.put(`/company/roles/${id}`, payload).then((r) => r.data.data),
    remove: (id) => api.delete(`/company/roles/${id}`),
};

export const companies = {
    list: (params) => api.get('/super-admin/companies', { params }).then((r) => r.data),
    get: (id) => api.get(`/super-admin/companies/${id}`).then((r) => r.data.data),
    create: (payload) => api.post('/super-admin/companies', payload).then((r) => r.data.data),
    update: (id, payload) => api.put(`/super-admin/companies/${id}`, payload).then((r) => r.data.data),
    setStatus: (id, status) => api.patch(`/super-admin/companies/${id}/status`, { status }).then((r) => r.data.data),
    remove: (id) => api.delete(`/super-admin/companies/${id}`),
    permissions: (id) => api.get(`/super-admin/companies/${id}/permissions`).then((r) => r.data.data),
    setPermissions: (id, disabled) => api.put(`/super-admin/companies/${id}/permissions`, { disabled }).then((r) => r.data.data),
};

// One mobile app, platform-wide — every company's conductors run the same
// build, so only the Super Admin publishes it (version, force-update,
// notes, APK). A company only ever reads this (see `mobileApp` below).
export const platformMobileApp = {
    get: () => api.get('/super-admin/mobile-app').then((r) => r.data.data),
    update: (payload) => api.put('/super-admin/mobile-app', payload).then((r) => r.data.data),
    uploadApk: (file) => {
        const fd = new FormData();
        fd.append('apk', file);
        return api.post('/super-admin/mobile-app/apk', fd).then((r) => r.data.data);
    },
    deleteApk: () => api.delete('/super-admin/mobile-app/apk').then((r) => r.data.data),
};

export const platformUsers = {
    list: (params) => api.get('/super-admin/users', { params }).then((r) => r.data),
    create: (payload) => api.post('/super-admin/users', payload).then((r) => r.data.data),
    update: (id, payload) => api.put(`/super-admin/users/${id}`, payload).then((r) => r.data.data),
    remove: (id) => api.delete(`/super-admin/users/${id}`),
};

export const systemConfiguration = {
    get: () => api.get('/super-admin/system-configuration').then((r) => r.data.data),
    update: (payload) => api.put('/super-admin/system-configuration', payload).then((r) => r.data.data),
    test: (url) => api.post('/super-admin/system-configuration/test', { url }).then((r) => r.data.data),
    history: (params) => api.get('/super-admin/system-configuration/history', { params }).then((r) => r.data),
    rollback: (id) => api.post(`/super-admin/system-configuration/history/${id}/rollback`).then((r) => r.data.data),
};

export const serverConfig = {
    // Public — no auth required (mobile app bootstrap; keyed by company code).
    get: (companyCode) => api.get('/meta/server-config', { params: { company: companyCode } }).then((r) => r.data.data),
};

// Public — no auth, no company code. One app, platform-wide; powers the
// login page's direct "Download" button.
export const publicMobileApp = {
    get: () => api.get('/meta/mobile-app').then((r) => r.data.data),
};

export const legalDocuments = {
    // Public — no auth required (mobile Legal screen, consent gate, public web pages).
    activePublic: () => api.get('/meta/legal').then((r) => r.data.data),
    accept: (payload) => api.post('/legal/accept', payload).then((r) => r.data),
    // Super Admin CMS.
    list: () => api.get('/super-admin/legal-documents').then((r) => r.data.data),
    active: (type) => api.get(`/super-admin/legal-documents/${type}/active`).then((r) => r.data.data),
    publish: (payload) => api.post('/super-admin/legal-documents', payload).then((r) => r.data.data),
};

export const companyProfile = {
    get: () => api.get('/company/profile').then((r) => r.data.data),
    update: (payload) => api.put('/company/profile', payload).then((r) => r.data.data),
};

export const companySettings = {
    get: () => api.get('/company/settings').then((r) => r.data.data),
    update: (payload) => api.put('/company/settings', payload).then((r) => r.data.data),
    configuration: () => api.get('/company/configuration').then((r) => r.data.data),
    // `type` is 'logo' | 'qr-payment'
    uploadImage: (type, file) => {
        const fd = new FormData();
        fd.append('image', file);
        return api.post(`/company/settings/${type}`, fd).then((r) => r.data.data);
    },
    deleteImage: (type) => api.delete(`/company/settings/${type}`).then((r) => r.data.data),
};

export const companyUsers = {
    list: (params) => api.get('/company/users', { params }).then((r) => r.data),
    get: (id) => api.get(`/company/users/${id}`).then((r) => r.data.data),
    create: (payload) => api.post('/company/users', payload).then((r) => r.data.data),
    update: (id, payload) => api.put(`/company/users/${id}`, payload).then((r) => r.data.data),
    remove: (id) => api.delete(`/company/users/${id}`),
    permissions: (id) => api.get(`/company/users/${id}/permissions`).then((r) => r.data),
    syncPermissions: (id, permissions) => api.put(`/company/users/${id}/permissions`, { permissions }).then((r) => r.data),
    resetPermissions: (id) => api.post(`/company/users/${id}/permissions/reset`).then((r) => r.data),
    lock: (id) => api.post(`/company/users/${id}/lock`).then((r) => r.data.data),
    unlock: (id) => api.post(`/company/users/${id}/unlock`).then((r) => r.data.data),
};

export const permissionCatalogue = {
    list: () => api.get('/company/permissions').then((r) => r.data.data),
};

export const buses = {
    list: (params) => api.get('/company/buses', { params }).then((r) => r.data),
    create: (payload) => api.post('/company/buses', payload).then((r) => r.data.data),
    update: (id, payload) => api.put(`/company/buses/${id}`, payload).then((r) => r.data.data),
    remove: (id) => api.delete(`/company/buses/${id}`),
};

export const thermalPrinters = {
    list: (params) => api.get('/company/thermal-printers', { params }).then((r) => r.data),
    create: (payload) => api.post('/company/thermal-printers', payload).then((r) => r.data.data),
    update: (id, payload) => api.put(`/company/thermal-printers/${id}`, payload).then((r) => r.data.data),
    remove: (id) => api.delete(`/company/thermal-printers/${id}`),
    assign: (id, userId) => api.put(`/company/thermal-printers/${id}/assign`, { user_id: userId }).then((r) => r.data.data),
};

export const adminAssignments = {
    list: (params) => api.get('/company/admin-assignments', { params }).then((r) => r.data),
    create: (payload) => api.post('/company/admin-assignments', payload).then((r) => r.data.data),
    update: (id, payload) => api.put(`/company/admin-assignments/${id}`, payload).then((r) => r.data.data),
    remove: (id) => api.delete(`/company/admin-assignments/${id}`),
};

export const terminals = {
    list: (params) => api.get('/company/terminals', { params }).then((r) => r.data),
    create: (payload) => api.post('/company/terminals', payload).then((r) => r.data.data),
    update: (id, payload) => api.put(`/company/terminals/${id}`, payload).then((r) => r.data.data),
    remove: (id) => api.delete(`/company/terminals/${id}`),
    routeStops: () => api.get('/company/route-stops').then((r) => r.data.data),
};

export const conductor = {
    activeTrip: () => api.get('/conductor/trips/active').then((r) => r.data.data),
    history: (params) => api.get('/conductor/trips/history', { params }).then((r) => r.data),
    lookupBuses: () => api.get('/conductor/lookup/buses').then((r) => r.data.data),
    lookupTerminals: () => api.get('/conductor/lookup/terminals').then((r) => r.data.data),
    lookupCoverage: () => api.get('/conductor/lookup/coverage').then((r) => r.data),
    lookupRoutes: () => api.get('/conductor/lookup/routes').then((r) => r.data.data),
    lookupRouteCoverage: (franchiseId) => api.get(`/conductor/lookup/routes/${franchiseId}/coverage`).then((r) => r.data),
    lookupRouteTerminals: (franchiseId) => api.get(`/conductor/lookup/routes/${franchiseId}/terminals`).then((r) => r.data.data),
    lookupPassengerTypes: () => api.get('/conductor/lookup/passenger-types').then((r) => r.data.data),
    tripFares: (tripId) => api.get(`/conductor/trips/${tripId}/fares`).then((r) => r.data),
    startTrip: (payload) => api.post('/conductor/trips', payload).then((r) => r.data.data),
    markOnTrip: () => api.post('/conductor/trips/mark-on-trip').then((r) => r.data.data),
    endTrip: (payload) => api.post('/conductor/trips/end', payload ?? {}).then((r) => r.data.data),
    cancelTrip: (reason) => api.post('/conductor/trips/cancel', { reason }).then((r) => r.data.data),
    issueTicket: (payload) => api.post('/conductor/tickets', payload).then((r) => r.data),
    issueTicketGroup: (payload) => api.post('/conductor/ticket-groups', payload).then((r) => r.data),
    tripTickets: (tripId) => api.get(`/conductor/trips/${tripId}/tickets`).then((r) => r.data.data),
    remittance: (tripId) => api.get(`/conductor/trips/${tripId}/remittance`).then((r) => r.data.data),
    attendance: () => api.get('/conductor/attendance').then((r) => r.data),
    toggleAttendance: (source = 'web') => api.post('/conductor/attendance/toggle', { source }).then((r) => r.data),
    dispatches: (tripId) => api.get(`/conductor/trips/${tripId}/dispatches`).then((r) => r.data),
    addDispatch: (payload) => api.post('/conductor/dispatches', payload).then((r) => r.data),
    removeDispatch: (tripId, id) => api.delete(`/conductor/trips/${tripId}/dispatches/${id}`).then((r) => r.data),
};

export const attendance = {
    list: (params) => api.get('/company/attendance', { params }).then((r) => r.data),
    close: (id, payload) => api.post(`/company/attendance/${id}/close`, payload).then((r) => r.data.data),
};

// Per bus/day/shift cash rollup — built from received remittances; a
// manager may re-tally its denominations (void-PIN required).
export const cashCounts = {
    list: (params) => api.get('/company/cash-counts', { params }).then((r) => r.data),
    get: (id) => api.get(`/company/cash-counts/${id}`).then((r) => r.data),
    adjust: (id, payload) => api.post(`/company/cash-counts/${id}/adjust`, payload).then((r) => r.data.data),
};

export const expenses = {
    list: (params) => api.get('/company/expenses', { params }).then((r) => r.data),
    create: (payload) => api.post('/company/expenses', payload).then((r) => r.data.data),
    void: (id, payload) => api.post(`/company/expenses/${id}/void`, payload).then((r) => r.data.data),
};

export const fuel = {
    list: (params) => api.get('/company/fuel', { params }).then((r) => r.data),
    create: (payload) => api.post('/company/fuel', payload).then((r) => r.data.data),
    remove: (id) => api.delete(`/company/fuel/${id}`),
};

export const charging = {
    list: (params) => api.get('/company/charging', { params }).then((r) => r.data),
    start: (payload) => api.post('/company/charging', payload).then((r) => r.data.data),
    end: (id, payload) => api.post(`/company/charging/${id}/end`, payload).then((r) => r.data.data),
};

export const voidPin = {
    status: () => api.get('/company/void-pin').then((r) => r.data),
    set: (payload) => api.put('/company/void-pin', payload).then((r) => r.data),
};

export const voidSecurity = {
    list: () => api.get('/company/void-security').then((r) => r.data.data),
    attempts: (params) => api.get('/company/void-security/attempts', { params }).then((r) => r.data),
    reset: (userId) => api.post(`/company/void-security/${userId}/reset`).then((r) => r.data),
    unlock: (userId) => api.post(`/company/void-security/${userId}/unlock`).then((r) => r.data),
};

// The Remittance desk: Received (count cash) → Approved; manager-only void.
export const remittances = {
    list: (params) => api.get('/company/remittances', { params }).then((r) => r.data),
    get: (tripId) => api.get(`/company/remittances/${tripId}`).then((r) => r.data),
    lock: (tripId) => api.post(`/company/remittances/${tripId}/lock`).then((r) => r.data),
    unlock: (tripId) => api.delete(`/company/remittances/${tripId}/lock`).then((r) => r.data),
    receive: (tripId, denominations) => api.post(`/company/remittances/${tripId}/receive`, denominations).then((r) => r.data),
    void: (tripId, payload) => api.post(`/company/remittances/${tripId}/void`, payload).then((r) => r.data),
    approve: (tripId, payload) => api.post(`/company/remittances/${tripId}/approve`, payload ?? {}).then((r) => r.data.data),
    flag: (tripId, flagged, note) => api.post(`/company/remittances/${tripId}/flag`, { flagged, note }).then((r) => r.data.data),
};

// Thermal receipt DTOs (BITS receipt/conductor/*.php) — rendered by
// resources/js/pages/ReceiptView.jsx at `receipt_width_mm`.
export const receipts = {
    conductorTrip: (tripId, kind) => api.get(`/conductor/trips/${tripId}/receipt/${kind}`).then((r) => r.data.data),
    companyTrip: (tripId, kind) => api.get(`/company/remittances/${tripId}/receipt/${kind}`).then((r) => r.data.data),
    monitorTrip: (tripId, kind) => api.get(`/company/trip-monitor/${tripId}/receipt/${kind}`).then((r) => r.data.data),
    ticket: (ticketId, qty = 1) => api.get(`/conductor/tickets/${ticketId}/receipt`, { params: { qty } }).then((r) => r.data.data),
    dispatch: (dispatchId) => api.get(`/conductor/dispatches/${dispatchId}/receipt`).then((r) => r.data.data),
    shiftSummary: (date) => api.get('/conductor/receipts/shift-summary', { params: date ? { date } : {} }).then((r) => r.data.data),
};

export const tripMonitor = {
    list: (params) => api.get('/company/trip-monitor', { params }).then((r) => r.data),
    get: (id) => api.get(`/company/trip-monitor/${id}`).then((r) => r.data),
    forceEnd: (id, reason) => api.post(`/company/trip-monitor/${id}/force-end`, { reason }).then((r) => r.data.data),
};

export const conductorBuses = {
    get: (userId) => api.get(`/company/users/${userId}/buses`).then((r) => r.data.data),
    sync: (userId, busIds) => api.put(`/company/users/${userId}/buses`, { bus_ids: busIds }).then((r) => r.data.data),
};

export const drivers = {
    list: (params) => api.get('/company/drivers', { params }).then((r) => r.data),
    create: (payload) => api.post('/company/drivers', payload).then((r) => r.data.data),
    update: (id, payload) => api.put(`/company/drivers/${id}`, payload).then((r) => r.data.data),
    remove: (id) => api.delete(`/company/drivers/${id}`),
};

export const passengerTypes = {
    list: (params) => api.get('/company/passenger-types', { params }).then((r) => r.data),
    create: (payload) => api.post('/company/passenger-types', payload).then((r) => r.data.data),
    update: (id, payload) => api.put(`/company/passenger-types/${id}`, payload).then((r) => r.data.data),
    remove: (id) => api.delete(`/company/passenger-types/${id}`),
    addArticle: (id, payload) => api.post(`/company/passenger-types/${id}/articles`, payload).then((r) => r.data.data),
    updateArticle: (id, articleId, payload) => api.put(`/company/passenger-types/${id}/articles/${articleId}`, payload).then((r) => r.data.data),
    removeArticle: (id, articleId) => api.delete(`/company/passenger-types/${id}/articles/${articleId}`),
};

export const franchises = {
    list: (params) => api.get('/company/franchises', { params }).then((r) => r.data),
    get: (id) => api.get(`/company/franchises/${id}`).then((r) => r.data.data),
    create: (payload) => api.post('/company/franchises', payload).then((r) => r.data.data),
    update: (id, payload) => api.put(`/company/franchises/${id}`, payload).then((r) => r.data.data),
    remove: (id) => api.delete(`/company/franchises/${id}`),

    // Fare-matrix grid
    grid: (id) => api.get(`/company/franchises/${id}/fare-matrix`).then((r) => r.data),
    saveCell: (id, payload) => api.put(`/company/franchises/${id}/fare-matrix/cell`, payload).then((r) => r.data.cell),
    stops: (id) => api.get(`/company/franchises/${id}/stops`).then((r) => r.data.data),
    saveStops: (id, stops, force = false) => api.put(`/company/franchises/${id}/stops`, { stops, force }).then((r) => r.data.data),

    downloadTemplate: async (id, filename = 'fare-matrix.xlsx') => {
        const res = await api.get(`/company/franchises/${id}/fare-matrix/template`, { responseType: 'blob' });
        const url = URL.createObjectURL(res.data);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    },
    importGrid: (id, file, clearBlanks = false) => {
        const fd = new FormData();
        fd.append('file', file);
        if (clearBlanks) fd.append('clear_blanks', '1');
        return api.post(`/company/franchises/${id}/fare-matrix/import`, fd).then((r) => r.data);
    },
};

/** Downloads a blob response as a file in the browser. */
async function downloadBlob(promise, filename) {
    const res = await promise;
    const url = URL.createObjectURL(res.data);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

export const dashboard = {
    get: () => api.get('/company/dashboard').then((r) => r.data.data),
};

export const live = {
    board: () => api.get('/company/live').then((r) => r.data.data),
};

export const conductorLocation = {
    ping: (payload) => api.post('/conductor/trips/location', payload).then((r) => r.data.data),
};

export const devices = {
    list: (params) => api.get('/company/devices', { params }).then((r) => r.data),
    remove: (id) => api.delete(`/company/devices/${id}`),
    register: (payload) => api.post('/conductor/devices/register', payload).then((r) => r.data.data),
};

export const auditLog = {
    list: (params) => api.get('/company/audit-log', { params }).then((r) => r.data),
    platform: (params) => api.get('/super-admin/audit-log', { params }).then((r) => r.data),
};

export const dataTools = {
    cleanPreview: () => api.get('/company/data-tools/clean-preview').then((r) => r.data.data),
    clean: (confirm) => api.post('/company/data-tools/clean', { confirm }).then((r) => r.data.data),
    history: () => api.get('/company/data-tools/history').then((r) => r.data.data),
    export: () => downloadBlob(
        api.get('/company/data-tools/export', { responseType: 'blob' }),
        `transitflow-export-${new Date().toISOString().slice(0, 10)}.json`,
    ),
};

// Read-only for a Company Admin / Chairman — the app itself is published
// platform-wide by the Super Admin (see `platformMobileApp` above).
export const mobileApp = {
    get: () => api.get('/company/mobile-app').then((r) => r.data.data),
};

/**
 * Reports & Analytics. `key` is one of income | trip-income | daily-operations
 * | expenses | cash-count | fuel-energy. `params` carries the report filters
 * (period/date/from/to/bus_id/type). `download(key, 'pdf'|'xlsx', params)`
 * streams the export.
 */
export const reports = {
    get: (key, params) => api.get(`/company/reports/${key}`, { params }).then((r) => r.data.data),
    download: (key, format, params = {}) =>
        downloadBlob(
            api.get(`/company/reports/${key}`, { params: { ...params, format }, responseType: 'blob' }),
            `${key}.${format}`,
        ),
};
