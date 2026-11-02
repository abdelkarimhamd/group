import * as Crypto from 'expo-crypto';
import createClient, { type Middleware } from 'openapi-fetch';

import { authToken } from '@/auth/token';
import { config } from '@/config';
import i18n from '@/i18n';

import { errorFromResponse, networkError } from './errors';
import type { paths } from './schema';

const conventions: Middleware = {
  onRequest({ request }) {
    request.headers.set('Accept', 'application/json');
    request.headers.set('Accept-Language', i18n.language);
    // Our own request ID, so a support ticket can be matched to API logs.
    request.headers.set('X-Request-Id', Crypto.randomUUID());
    const token = authToken.get();
    if (token) request.headers.set('Authorization', `Bearer ${token}`);
    return request;
  },
  onResponse({ request, response }) {
    if (response.status === 401 && request.headers.has('Authorization')) {
      authToken.invalidate();
    }
    return response;
  },
};

export const client = createClient<paths>({
  baseUrl: config.apiUrl,
  // Looked up per call so tests can swap globalThis.fetch.
  fetch: (request) => globalThis.fetch(request),
});
client.use(conventions);

type Result<T> = { data?: T; error?: unknown; response: Response };

/** Resolves to the response body, or throws an ApiError. */
export async function call<T>(request: Promise<Result<T>>): Promise<T> {
  let result: Result<T>;
  try {
    result = await request;
  } catch (cause) {
    throw networkError(cause);
  }
  if (!result.response.ok) {
    throw errorFromResponse(result.response, result.error);
  }
  return result.data as T;
}
