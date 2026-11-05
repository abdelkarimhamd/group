import { existsSync } from 'node:fs';

import type { ConfigContext, ExpoConfig } from 'expo/config';

/**
 * Adds what app.json cannot hold. Android push needs the Firebase project's
 * google-services.json: Expo's push service delivers through Firebase, and without it
 * the app gets no Expo push token on Android. The file is never committed. EAS builds
 * read it from the `GOOGLE_SERVICES_JSON` file variable; a local build uses
 * ./google-services.json when it exists.
 *
 * The EAS project (push tokens and store builds) comes from `EAS_PROJECT_ID` and its
 * Expo account from `EXPO_OWNER`, so the repository needs no edit once they exist
 * (README, Store builds).
 */
export default ({ config }: ConfigContext): ExpoConfig => {
  const googleServicesFile =
    process.env.GOOGLE_SERVICES_JSON ??
    (existsSync('./google-services.json') ? './google-services.json' : undefined);
  const projectId: unknown = process.env.EAS_PROJECT_ID ?? config.extra?.eas?.projectId;
  const owner = process.env.EXPO_OWNER ?? config.owner;

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
    android: { ...config.android, ...(googleServicesFile ? { googleServicesFile } : {}) },
  };
};
