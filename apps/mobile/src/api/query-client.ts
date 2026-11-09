import { focusManager, QueryClient } from '@tanstack/react-query';
import { AppState } from 'react-native';

import { isApiError } from './errors';

/**
 * React Native has no window focus events, so the query client would take the app for
 * always in front and keep polling (campaign progress every few seconds) in the background.
 * AppState tells it when the app leaves the screen and comes back.
 */
function followAppState(): void {
  focusManager.setEventListener((setFocused) => {
    const subscription = AppState.addEventListener('change', (state) =>
      setFocused(state === 'active'),
    );
    return () => subscription.remove();
  });
}

export function createQueryClient(): QueryClient {
  followAppState();
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        // Coming back to the app does not refetch everything: screens that need fresh
        // numbers on return (campaign progress) ask for them.
        refetchOnWindowFocus: false,
        // Retry only failures that a retry can fix: the network and the server.
        retry: (failureCount, error) =>
          failureCount < 2 && (!isApiError(error) || error.status === 0 || error.status >= 500),
      },
      mutations: { retry: false },
    },
  });
}
