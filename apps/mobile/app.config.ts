import { existsSync } from 'node:fs';

import type { ConfigContext, ExpoConfig } from 'expo/config';

/**
 * Adds what app.json cannot hold. Android push needs the Firebase project's
 * google-services.json: Expo's push service delivers through Firebase, and without it
 * the app gets no Expo push token on Android. The file is never committed. EAS builds
 * read it from the `GOOGLE_SERVICES_JSON` file variable; a local build uses
 * ./google-services.json when it exists.
 */
export default ({ config }: ConfigContext): ExpoConfig => {
  const googleServicesFile =
    process.env.GOOGLE_SERVICES_JSON ??
    (existsSync('./google-services.json') ? './google-services.json' : undefined);

  return {
    ...config,
    name: config.name ?? 'Group Buy',
    slug: config.slug ?? 'group-buy',
    android: { ...config.android, ...(googleServicesFile ? { googleServicesFile } : {}) },
  };
};
