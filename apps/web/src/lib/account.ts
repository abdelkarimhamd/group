import "server-only";
import type { Locale } from "@/i18n/config";
import { handlePageError, requestApi, requireToken, unwrap, type ApiClient } from "@/lib/api/server";

// Data loading for pages behind sign-in. A missing session goes to sign-in
// before any request; an expired one comes back as 401 and goes there too,
// with `next` pointing back at the page.

export async function accountApi(locale: Locale, nextPath: string): Promise<ApiClient> {
  const token = await requireToken(locale, nextPath);
  return requestApi(locale, token);
}

export async function loadOrRedirect<T>(
  call: Promise<{ data?: T; error?: unknown; response: Response }>,
  locale: Locale,
  nextPath: string,
): Promise<T> {
  try {
    return await unwrap(call);
  } catch (error) {
    handlePageError(error, locale, nextPath);
  }
}

export const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
