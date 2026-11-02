import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
import { BrowserRouter } from 'react-router-dom';
import { toApiError } from './api/errors';
import { AuthProvider } from './auth/AuthProvider';
import { LocaleProvider } from './i18n/LocaleProvider';
import { AppRoutes } from './routes';

export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 15_000,
        refetchOnWindowFocus: false,
        // Client errors will not fix themselves; retry only network and server errors.
        retry: (failureCount, error) => {
          const status = toApiError(error).status;
          return failureCount < 2 && (status === 0 || status >= 500);
        },
      },
    },
  });
}

export function Providers({
  children,
  queryClient,
}: {
  children: ReactNode;
  queryClient: QueryClient;
}) {
  return (
    <QueryClientProvider client={queryClient}>
      <LocaleProvider>
        <AuthProvider>{children}</AuthProvider>
      </LocaleProvider>
    </QueryClientProvider>
  );
}

export function App() {
  const [queryClient] = useState(createQueryClient);
  return (
    <Providers queryClient={queryClient}>
      <BrowserRouter>
        <AppRoutes />
      </BrowserRouter>
    </Providers>
  );
}
