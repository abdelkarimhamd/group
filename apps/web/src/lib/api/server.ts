import "server-only";
import createClient, { type Client } from "openapi-fetch";
import { redirect, notFound, unstable_rethrow } from "next/navigation";
import type { paths } from "./schema";
import { ApiError, parseRetryAfter } from "./errors";
import type { ApiErrorBody } from "./types";
import type { Locale } from "@/i18n/config";
import { getToken } from "@/lib/session";

export type ApiClient = Client<paths>;

export function apiBaseUrl(): string {
  return (process.env.API_BASE_URL ?? "http://localhost:4010").replace(/\/+$/, "");
}

/**
 * openapi-fetch hands over a Request whose body is a stream, which Node sends
 * with Transfer-Encoding: chunked. Some servers in front of PHP (and Prism)
 * reject chunked request bodies, so the body is buffered and sent with a
 * Content-Length instead.
 */
async function sendBuffered(request: Request): Promise<Response> {
  const body = request.body ? await request.arrayBuffer() : undefined;
  return fetch(request.url, {
    method: request.method,
    headers: request.headers,
    body,
    cache: "no-store",
    signal: request.signal,
  });
}

/**
 * A typed client for the API contract. Every call carries the page language,
 * and the customer's token when `token` is given. Responses are never cached:
 * quantities, prices and payment states change by the second.
 */
export function createApi(locale: Locale, token?: string): ApiClient {
  const client = createClient<paths>({
    baseUrl: apiBaseUrl(),
    cache: "no-store",
    fetch: sendBuffered,
    headers: {
      Accept: "application/json",
      "Accept-Language": locale,
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });
  return client;
}

/** The API client with the signed-in customer's token, if there is one. */
export async function sessionApi(locale: Locale): Promise<{ api: ApiClient; token?: string }> {
  const token = await getToken();
  return { api: createApi(locale, token), token };
}

type FetchResult<T> = { data?: T; error?: unknown; response: Response };

/**
 * Returns the response data, or throws ApiError. A network failure becomes
 * ApiError with status 0.
 */
export async function unwrap<T>(call: Promise<FetchResult<T>>): Promise<T> {
  let result: FetchResult<T>;
  try {
    result = await call;
  } catch (cause) {
    // Next interrupts fetches when a prerender finishes; let it handle those.
    unstable_rethrow(cause);
    console.error("API request failed", cause);
    throw new ApiError(0, { message: "network", code: "network" });
  }
  const { data, error, response } = result;
  if (response.ok) return data as T;
  throw new ApiError(
    response.status,
    (error ?? undefined) as ApiErrorBody | undefined,
    parseRetryAfter(response.headers.get("Retry-After")),
  );
}

/**
 * For Server Components: sends a missing or expired session to sign-in and
 * an unknown resource to the 404 page. Anything else is rethrown for the
 * nearest error boundary.
 */
export function handlePageError(error: unknown, locale: Locale, nextPath: string): never {
  if (error instanceof ApiError) {
    if (error.status === 401) {
      redirect(`/${locale}/sign-in?expired=1&next=${encodeURIComponent(nextPath)}`);
    }
    if (error.status === 404) notFound();
  }
  throw error;
}

/**
 * For public sections that should degrade instead of failing the page:
 * the data, or null when the API could not be reached or said no.
 */
export async function orNull<T>(promise: Promise<T>): Promise<T | null> {
  try {
    return await promise;
  } catch (error) {
    unstable_rethrow(error);
    console.error("API section failed", error instanceof ApiError ? `${error.status} ${error.code ?? ""}` : error);
    return null;
  }
}

/** Server Components behind sign-in call this first. */
export async function requireToken(locale: Locale, nextPath: string): Promise<string> {
  const token = await getToken();
  if (!token) redirect(`/${locale}/sign-in?next=${encodeURIComponent(nextPath)}`);
  return token;
}
