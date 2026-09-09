import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

import { auth as authApi } from '../lib/api.js';
import { getToken, setToken, setUnauthorizedHandler } from '../lib/axios.js';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);

    const clear = useCallback(() => {
        setToken(null);
        setUser(null);
    }, []);

    // Global 401 handler (fires from the axios interceptor).
    useEffect(() => {
        setUnauthorizedHandler(() => setUser(null));
    }, []);

    // Restore the session on first load.
    useEffect(() => {
        let active = true;
        if (!getToken()) {
            setLoading(false);
            return;
        }
        authApi
            .me()
            .then((u) => active && setUser(u))
            .catch(() => active && clear())
            .finally(() => active && setLoading(false));
        return () => {
            active = false;
        };
    }, [clear]);

    const login = useCallback(async (credentials) => {
        const { token, user: u } = await authApi.login(credentials);
        setToken(token);
        setUser(u);
        return u;
    }, []);

    const refreshUser = useCallback(async () => {
        const u = await authApi.me();
        setUser(u);
        return u;
    }, []);

    const logout = useCallback(async () => {
        try {
            await authApi.logout();
        } catch {
            /* ignore — clearing locally is what matters */
        }
        clear();
    }, [clear]);

    const value = useMemo(() => {
        const perms = new Set(user?.permissions ?? []);
        return {
            user,
            loading,
            login,
            logout,
            refreshUser,
            isAuthenticated: !!user,
            isSuperAdmin: !!user?.is_super_admin,
            isCompanyAdmin: !!user?.is_company_admin,
            mustChangePassword: !!user?.must_change_password,
            can: (key) => !!user && (user.is_super_admin || perms.has(key)),
        };
    }, [user, loading, login, logout, refreshUser]);

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
    const ctx = useContext(AuthContext);
    if (!ctx) {
        throw new Error('useAuth must be used within <AuthProvider>');
    }
    return ctx;
}
