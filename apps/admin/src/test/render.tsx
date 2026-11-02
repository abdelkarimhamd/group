import { render } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { MemoryRouter } from 'react-router-dom';
import type { AdminUser } from '../api/types';
import { createQueryClient, Providers } from '../App';
import { AppRoutes } from '../routes';
import { API, server } from './server';

/**
 * Renders the whole portal at `route`. With an admin, the session is already
 * signed in and GET /admin/me answers with that admin.
 */
export function renderApp(route: string, { admin }: { admin?: AdminUser } = {}) {
  if (admin) {
    sessionStorage.setItem('groupbuy.admin.token', 'test-token');
    server.use(http.get(`${API}/admin/me`, () => HttpResponse.json({ data: admin })));
  }
  const queryClient = createQueryClient();
  const user = userEvent.setup();
  const view = render(
    <Providers queryClient={queryClient}>
      <MemoryRouter initialEntries={[route]}>
        <AppRoutes />
      </MemoryRouter>
    </Providers>,
  );
  return { ...view, user, queryClient };
}

/** Records the JSON bodies a handler receives. */
export function bodies<T = unknown>() {
  const seen: T[] = [];
  return {
    seen,
    async record(request: Request) {
      seen.push((await request.clone().json()) as T);
    },
  };
}
