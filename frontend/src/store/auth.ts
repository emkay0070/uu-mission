/**
 * store/auth.ts — Zustand auth store.
 * Persists the token to localStorage. Reads user from /auth/me on load.
 */
import { create } from 'zustand';
import { authApi, type AuthUser } from '@/lib/api';

interface AuthState {
  user: AuthUser | null;
  token: string | null;
  loading: boolean;
  initialized: boolean;
  login:      (email: string, password: string) => Promise<void>;
  logout:     () => Promise<void>;
  initialize: () => Promise<void>;
  refreshUser: () => Promise<void>;
}

export const useAuthStore = create<AuthState>((set, get) => ({
  user:        null,
  token:       null,
  loading:     false,
  initialized: false,

  initialize: async () => {
    if (get().initialized) return;
    const stored = typeof window !== 'undefined' ? localStorage.getItem('uu_token') : null;
    if (!stored) {
      set({ initialized: true });
      return;
    }
    set({ token: stored, loading: true });
    try {
      const { user } = await authApi.me();
      set({ user, loading: false, initialized: true });
    } catch {
      localStorage.removeItem('uu_token');
      set({ token: null, user: null, loading: false, initialized: true });
    }
  },

  login: async (email, password) => {
    set({ loading: true });
    const { token, user } = await authApi.login(email, password, 'UU Mission Web');
    localStorage.setItem('uu_token', token);
    set({ token, user, loading: false });
  },

  logout: async () => {
    try { await authApi.logout(); } catch { /* ignore */ }
    localStorage.removeItem('uu_token');
    // Keep initialized: true — it means "auth state is known (no user)".
    // Resetting to false would cause AuthGuard to re-run initialize(),
    // flipping initialized back to true and triggering the guard again (loop).
    set({ user: null, token: null, initialized: true });
  },

  refreshUser: async () => {
    const token = get().token;
    if (!token) return;
    set({ loading: true });
    try {
      const { user } = await authApi.me();
      set({ user, loading: false });
    } catch {
      localStorage.removeItem('uu_token');
      set({ token: null, user: null, loading: false });
    }
  },
}));
