import type { Locale } from '@/i18n/locale';

/** Pages the app shows from the website, so their text lives in one place. */
export type WebPage = 'privacy' | 'terms' | 'returns';

/** A page of the website in the app's language, such as https://example.sa/ar/terms. */
export function webPageUrl(webUrl: string, locale: Locale, page: WebPage): string {
  return `${webUrl}/${locale}/${page}`;
}

/** The website's page for a deal: what the app shares, and what opens the app when tapped. */
export function campaignUrl(webUrl: string, locale: Locale, campaignId: string): string {
  return `${webUrl}/${locale}/campaigns/${campaignId}`;
}

const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

/** Website paths (after the /ar or /en prefix) and the app screens they open. */
const WEBSITE_ROUTES: [RegExp, (id: string) => string][] = [
  [new RegExp(`^/campaigns/(${UUID})(?:/join)?/?$`, 'i'), (id) => `/campaign/${id}`],
  [/^\/campaigns\/?$/, () => '/'],
  [new RegExp(`^/account/joins/(${UUID})/?$`, 'i'), (id) => `/joins/${id}`],
  [/^\/account\/joins\/?$/, () => '/joins'],
  [new RegExp(`^/account/orders/(${UUID})/?$`, 'i'), (id) => `/orders/${id}`],
  [/^\/account\/orders\/?$/, () => '/orders'],
  [/^\/account\/addresses\/?$/, () => '/addresses'],
  [/^\/account\/?$/, () => '/account'],
  [/^\/how-it-works\/?$/, () => '/how-it-works'],
  [/^\/?$/, () => '/'],
];

/**
 * The app screen for a link to the website (a universal link on iOS, an app link on Android),
 * such as https://example.sa/ar/campaigns/<id> or the path /en/account/orders/<id>. Website
 * paths start with the language. Returns null for anything else, which the app opens as it
 * is: its own routes never start with /ar or /en. A website page the app has no screen for
 * opens the deals list.
 */
export function appPathForWebsiteLink(link: string): string | null {
  let path = link;
  const absolute = /^[a-z][a-z0-9+.-]*:\/\/[^/]*(.*)$/i.exec(link);
  if (absolute) path = absolute[1] || '/';
  path = path.split(/[?#]/)[0] ?? '/';
  if (!path.startsWith('/')) path = `/${path}`;

  const localized = /^\/(ar|en)(\/.*)?$/.exec(path);
  if (!localized) return null;
  const rest = localized[2] ?? '/';
  for (const [pattern, screen] of WEBSITE_ROUTES) {
    const match = pattern.exec(rest);
    if (match) return screen(match[1]?.toLowerCase() ?? '');
  }
  return '/';
}
