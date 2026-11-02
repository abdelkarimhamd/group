import { QueryClient } from '@tanstack/react-query';

import { isApiError } from './errors';

export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        // Retry only failures that a retry can fix: the network and the server.
        retry: (failureCount, error) =>
          failureCount < 2 && (!isApiError(error) || error.status === 0 || error.status >= 500),
      },
      mutations: { retry: false },
    },
  });
}
