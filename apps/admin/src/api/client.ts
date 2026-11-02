import createFetchClient, { type Middleware } from 'openapi-fetch';
import createQueryClient from 'openapi-react-query';
import { API_BASE_PATH } from '../config';
import { ApiError } from './errors';
import type { paths } from './schema';

type Session = {
  getToken: () => string | null;
  getLocale: () => string;
  onUnauthenticated: () => void;
};

let session: Session = {
  getToken: () => null,
  getLocale: () => 'ar',
  onUnauthenticated: () => {},
};

/** Wired once by the auth and locale providers. */
export function configureApiSession(next: Partial<Session>): void {
  session = { ...session, ...next };
}

const REQUEST_ID_HEADER = 'X-Request-Id';

function newRequestId(): string {
  return globalThis.crypto?.randomUUID?.() ?? `adm-${Date.now().toString(36)}`;
}

const sessionMiddleware: Middleware = {
  onRequest({ request }) {
    const token = session.getToken();
    if (token) request.headers.set('Authorization', `Bearer ${token}`);
    request.headers.set('Accept', 'application/json');
    request.headers.set('Accept-Language', session.getLocale());
    if (!request.headers.has(REQUEST_ID_HEADER)) {
      request.headers.set(REQUEST_ID_HEADER, newRequestId());
    }
    return request;
  },
  async onResponse({ request, response }) {
    if (response.ok) return undefined;
    const error = await ApiError.fromResponse(response);
    if (response.status === 401 && request.headers.has('Authorization')) {
      session.onUnauthenticated();
    }
    throw error;
  },
};

function baseUrl(): string {
  const origin = globalThis.location?.origin ?? 'http://localhost';
  return new URL(API_BASE_PATH, origin.endsWith('/') ? origin : `${origin}/`).toString();
}

/**
 * Typed client generated from the contract. Every non-2xx response throws an
 * ApiError, so callers only see data on success.
 */
export const api = createFetchClient<paths>({
  baseUrl: baseUrl(),
  // Look fetch up on every call so test doubles installed later are used.
  fetch: (request) => globalThis.fetch(request),
});
api.use(sessionMiddleware);

/** TanStack Query hooks over the same client: $api.useQuery('get', '/admin/me'). */
export const $api = createQueryClient(api);

/** Multipart body for the upload endpoints. */
export function formDataSerializer(body: Record<string, unknown>): FormData {
  const form = new FormData();
  for (const [key, value] of Object.entries(body)) {
    if (value === undefined || value === null) continue;
    if (value instanceof Blob) form.append(key, value);
    else form.append(key, String(value));
  }
  return form;
}
