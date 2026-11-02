import { useQueryClient } from '@tanstack/react-query';
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';
import { $api, api, configureApiSession } from '../api/client';
import type { AdminSession, AdminUser } from '../api/types';
import { hasPermission, type Permission } from './permissions';
import { readToken, writeToken } from './storage';

type AuthContextValue = {
  token: string | null;
  admin: AdminUser | undefined;
  /** True while a stored token is being checked against GET /admin/me. */
  loading: boolean;
  /** Set when the stored token could not be checked (not a 401, which signs out). */
  error: Error | null;
  reload: () => void;
  signIn: (session: AdminSession) => void;
  signOut: () => Promise<void>;
  can: (permission: Permission) => boolean;
};

/**
 * The mock server's example admin holds only a few permissions. For building
 * screens against it, `npm run dev:mock` shows every action. Development
 * builds only; the API enforces permissions either way.
 */
const SHOW_ALL_ACTIONS =
  import.meta.env.DEV && import.meta.env.VITE_DEV_SHOW_ALL_ACTIONS === 'true';

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const [token, setToken] = useState<string | null>(() => readToken());

  const clearSession = useCallback(() => {
    writeToken(null);
    setToken(null);
    queryClient.clear();
  }, [queryClient]);

  useEffect(() => {
    configureApiSession({ getToken: readToken, onUnauthenticated: clearSession });
  }, [clearSession]);

  const me = $api.useQuery('get', '/admin/me', undefined, {
    enabled: token !== null,
    staleTime: 5 * 60_000,
    retry: false,
  });

  const signIn = useCallback(
    (session: AdminSession) => {
      writeToken(session.token);
      queryClient.setQueryData(['get', '/admin/me'], { data: session.admin });
      setToken(session.token);
    },
    [queryClient],
  );

  const signOut = useCallback(async () => {
    try {
      await api.POST('/admin/auth/logout');
    } catch {
      // The token is dropped locally whatever the API says.
    }
    clearSession();
  }, [clearSession]);

  const admin = token ? me.data?.data : undefined;

  const value = useMemo<AuthContextValue>(
    () => ({
      token,
      admin,
      loading: token !== null && me.isPending,
      error: token !== null && me.isError ? (me.error as unknown as Error) : null,
      reload: () => void me.refetch(),
      signIn,
      signOut,
      can: (permission) => SHOW_ALL_ACTIONS || hasPermission(admin, permission),
    }),
    [token, admin, me, signIn, signOut],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider');
  return ctx;
}

/** Shows children only when the signed-in admin holds the permission. */
export function Can({ permission, children }: { permission: Permission; children: ReactNode }) {
  const { can } = useAuth();
  return can(permission) ? <>{children}</> : null;
}
