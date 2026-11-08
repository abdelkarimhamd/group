import { existsSync } from 'node:fs';

import type { ConfigContext, ExpoConfig } from 'expo/config';

/**
 * Adds what app.json cannot hold. Android push needs the Firebase project's
 * google-services.json: Expo's push service delivers through Firebase, and without it
 * the app gets no Expo push token on Android. The file is never committed. EAS builds
 * read it from the `GOOGLE_SERVICES_JSON` file variable; a local build uses
 * ./google-services.json when it exists.
 *
 * Links to the website open the app (see APP_LINK_PATHS) once EXPO_PUBLIC_WEB_URL is the
 * https site and the website serves the matching files with the Apple team ID and the
 * Android signing certificate (apps/web README).
 *
 * The EAS project (push tokens and store builds) comes from `EAS_PROJECT_ID` and its
 * Expo account from `EXPO_OWNER`, so the repository needs no edit once they exist
 * (README, Store builds).
 */
/**
 * Website paths that open the app instead of the browser (universal links on iOS, app links
 * on Android): deals and the customer's joins and orders. Never the payment pages, which the
 * app itself opens in a WebView. The website lists the same paths in
 * /.well-known/apple-app-site-association and /.well-known/assetlinks.json.
 */
export const APP_LINK_PATHS = ['/ar/campaigns', '/en/campaigns', '/ar/account', '/en/account'];

/** The website's host when EXPO_PUBLIC_WEB_URL is https, the only scheme app links use. */
function appLinkHost(url: string | undefined): string | null {
  if (!url) return null;
  try {
    const parsed = new URL(url);
    return parsed.protocol === 'https:' ? parsed.host : null;
  } catch {
    return null;
  }
}

export default ({ config }: ConfigContext): ExpoConfig => {
  const googleServicesFile =
    process.env.GOOGLE_SERVICES_JSON ??
    (existsSync('./google-services.json') ? './google-services.json' : undefined);
  const projectId: unknown = process.env.EAS_PROJECT_ID ?? config.extra?.eas?.projectId;
  const owner = process.env.EXPO_OWNER ?? config.owner;
  const linkHost = appLinkHost(process.env.EXPO_PUBLIC_WEB_URL);

  return {
    ...config,
    name: config.name ?? 'Group Buy',
    slug: config.slug ?? 'group-buy',
    ...(owner ? { owner } : {}),
    extra: {
      ...config.extra,
      ...(typeof projectId === 'string' && projectId !== ''
        ? { eas: { ...config.extra?.eas, projectId } }
        : {}),
    },
    ios: {
      ...config.ios,
      ...(linkHost ? { associatedDomains: [`applinks:${linkHost}`] } : {}),
    },
    android: {
      ...config.android,
      ...(googleServicesFile ? { googleServicesFile } : {}),
      ...(linkHost
        ? {
            intentFilters: [
              {
                action: 'VIEW',
                autoVerify: true,
                data: APP_LINK_PATHS.map((pathPrefix) => ({
                  scheme: 'https',
                  host: linkHost,
                  pathPrefix,
                })),
                category: ['BROWSABLE', 'DEFAULT'],
              },
            ],
          }
        : {}),
    },
  };
};
