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

import { api } from '@/api/endpoints';
import { clearPrivateData, queryKeys } from '@/api/queries';
import type { CustomerSession } from '@/api/types';
import i18n from '@/i18n';
import { isLocale } from '@/i18n/locale';
import { registerForPush } from '@/push';

import { authToken } from './token';

type AuthContextValue = {
  isSignedIn: boolean;
  signIn: (session: CustomerSession) => void;
  /**
   * Forgets the session on this device. `revoke: false` skips telling the API, for a token
   * that already stopped working (the account was deleted).
   */
  signOut: (options?: { revoke?: boolean }) => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const [isSignedIn, setSignedIn] = useState(() => authToken.get() !== null);

  // Already signed in at launch: refresh this device's push token with the API (Expo can hand
  // out a new one), without asking for permission again.
  useEffect(() => {
    if (authToken.get()) void registerForPush({ prompt: false });
  }, []);

  // The API rejected the token: drop the customer's data; the next protected action asks
  // them to sign in again.
  useEffect(
    () =>
      authToken.onInvalidated(() => {
        clearPrivateData(queryClient);
        setSignedIn(false);
      }),
    [queryClient],
  );

  const signIn = useCallback(
    (session: CustomerSession) => {
      authToken.set(session.token);
      queryClient.setQueryData(queryKeys.me, session.user);
      setSignedIn(true);
      // SMS and push messages follow the account language; keep it in step with the app.
      const appLocale = i18n.language;
      if (isLocale(appLocale) && session.user.locale !== appLocale) {
        api
          .updateMe({ locale: appLocale })
          .then((user) => queryClient.setQueryData(queryKeys.me, user))
          .catch(() => {});
      }
      void registerForPush();
    },
    [queryClient],
  );

  const signOut = useCallback(
    async ({ revoke = true }: { revoke?: boolean } = {}) => {
      if (revoke && authToken.get()) {
        await api.logout().catch(() => {});
      }
      await authToken.clear();
      clearPrivateData(queryClient);
      setSignedIn(false);
    },
    [queryClient],
  );

  const value = useMemo<AuthContextValue>(
    () => ({ isSignedIn, signIn, signOut }),
    [isSignedIn, signIn, signOut],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const value = useContext(AuthContext);
  if (!value) throw new Error('useAuth must be used inside <AuthProvider>');
  return value;
}
