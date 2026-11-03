import type { MoyasarCheckout } from '@/api/types';
import type { Locale } from '@/i18n/locale';

/**
 * The website page that shows Moyasar's form inside the app (apps/web, /[locale]/payments/app).
 * The checkout travels in the URL fragment, which never reaches a server; the page checks it
 * and refuses any checkout that would not come back to the same site.
 */
export function paymentPageUrl(webUrl: string, locale: Locale, checkout: MoyasarCheckout): string {
  return `${webUrl}/${locale}/payments/app#${encodeURIComponent(JSON.stringify(checkout))}`;
}

/** The page tells the app Moyasar's payment ID as soon as the payment exists (before 3-D Secure). */
export function providerPaymentIdFromMessage(data: string): string | null {
  let message: unknown;
  try {
    message = JSON.parse(data);
  } catch {
    return null;
  }
  if (!message || typeof message !== 'object') return null;
  const { type, id } = message as { type?: unknown; id?: unknown };
  return type === 'moyasar_payment' && isProviderPaymentId(id) ? id : null;
}

function isProviderPaymentId(value: unknown): value is string {
  return typeof value === 'string' && /^[A-Za-z0-9_-]{1,64}$/.test(value);
}

/** Origin, path and query of an http(s) URL; React Native's URL support is too thin to rely on. */
function splitUrl(
  url: string,
): { origin: string; path: string; query: Map<string, string> } | null {
  const match = /^(https?:\/\/[^/?#]+)([^?#]*)(?:\?([^#]*))?/i.exec(url);
  if (!match) return null;
  const query = new Map<string, string>();
  for (const pair of (match[3] ?? '').split('&')) {
    if (!pair) continue;
    const eq = pair.indexOf('=');
    try {
      const key = decodeURIComponent(eq === -1 ? pair : pair.slice(0, eq));
      const value = eq === -1 ? '' : decodeURIComponent(pair.slice(eq + 1).replace(/\+/g, ' '));
      if (!query.has(key)) query.set(key, value);
    } catch {
      // A malformed pair is skipped; Moyasar's own parameters are plain.
    }
  }
  return { origin: match[1].toLowerCase(), path: match[2] || '/', query };
}

/**
 * Whether `url` is the form's return to the checkout's callback_url, and with which Moyasar
 * payment ID (Moyasar adds `id`, `status` and `message`; only the ID is used, because the API
 * asks Moyasar itself). The website may also have put a language in front of the path.
 */
export function returnFromForm(
  url: string,
  checkout: MoyasarCheckout,
): { providerPaymentId: string | null } | null {
  const callback = splitUrl(checkout.callback_url);
  const target = splitUrl(url);
  if (!callback || !target || target.origin !== callback.origin) return null;
  const path = target.path.replace(/^\/(ar|en)(?=\/)/, '');
  if (path !== callback.path) return null;
  const id = target.query.get('id');
  return { providerPaymentId: isProviderPaymentId(id) ? id : null };
}
