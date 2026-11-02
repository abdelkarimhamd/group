import { useQueryClient } from '@tanstack/react-query';
import { reloadAppAsync } from 'expo';
import { useCallback } from 'react';

import { api } from '@/api/endpoints';
import { queryKeys } from '@/api/queries';
import { useAuth } from '@/auth/session';

import i18n from './index';
import { applyLayoutDirection, saveLocale, type Locale } from './locale';

/**
 * Switches the app language, tells the API (SMS and push follow the account language), and
 * reloads when the layout direction has to flip.
 */
export function useChangeLocale() {
  const queryClient = useQueryClient();
  const { isSignedIn } = useAuth();

  return useCallback(
    async (next: Locale) => {
      saveLocale(next);
      await i18n.changeLanguage(next);
      if (isSignedIn) {
        await api
          .updateMe({ locale: next })
          .then((user) => queryClient.setQueryData(queryKeys.me, user))
          .catch(() => {});
      }
      if (applyLayoutDirection(next)) {
        await reloadAppAsync('Language changed');
        return;
      }
      // Campaign titles and API messages come back in the request language.
      await queryClient.resetQueries();
    },
    [isSignedIn, queryClient],
  );
}
