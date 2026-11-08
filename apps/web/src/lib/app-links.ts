import { locales } from "@/i18n/config";

// The files that let links to this site open the mobile app: universal links on
// iOS (/.well-known/apple-app-site-association) and app links on Android
// (/.well-known/assetlinks.json). Both name the same paths as APP_LINK_PATHS in
// apps/mobile/app.config.ts: deals and the customer's account pages. Never the
// payment pages, which the app opens in its own browser view and which must
// come back to the website.

/** Website sections that open the app, under each locale. */
export const APP_LINK_SECTIONS = ["campaigns", "account"] as const;

type Env = Record<string, string | undefined>;

const DEFAULT_APP_ID = "sa.groupbuy.app";

function value(env: Env, key: string): string | null {
  const raw = env[key]?.trim();
  return raw ? raw : null;
}

/** The iOS file, or null until the Apple team ID is set. */
export function appleAppSiteAssociation(env: Env = process.env) {
  const teamId = value(env, "APPLE_TEAM_ID");
  if (!teamId) return null;
  const bundleId = value(env, "IOS_BUNDLE_ID") ?? DEFAULT_APP_ID;
  const components = locales.flatMap((locale) =>
    APP_LINK_SECTIONS.flatMap((section) => [
      { "/": `/${locale}/${section}` },
      { "/": `/${locale}/${section}/*` },
    ]),
  );
  return {
    applinks: { details: [{ appIDs: [`${teamId}.${bundleId}`], components }] },
  };
}

/**
 * The Android file, or null until the signing certificate is set. Google Play
 * signs the app with its own key, so ANDROID_CERT_SHA256 takes several
 * fingerprints separated by commas (the Play signing key and the upload key).
 */
export function androidAssetLinks(env: Env = process.env) {
  const fingerprints = (value(env, "ANDROID_CERT_SHA256") ?? "")
    .split(",")
    .map((fingerprint) => fingerprint.trim().toUpperCase())
    .filter((fingerprint) => /^([0-9A-F]{2}:){31}[0-9A-F]{2}$/.test(fingerprint));
  if (fingerprints.length === 0) return null;
  return [
    {
      relation: ["delegate_permission/common.handle_all_urls"],
      target: {
        namespace: "android_app",
        package_name: value(env, "ANDROID_APP_PACKAGE") ?? DEFAULT_APP_ID,
        sha256_cert_fingerprints: fingerprints,
      },
    },
  ];
}

/** The response for one of the files: JSON as it is, or 404 while unconfigured. */
export function appLinkResponse(body: unknown): Response {
  if (body === null) return new Response("Not found", { status: 404 });
  return Response.json(body, { headers: { "Cache-Control": "public, max-age=3600" } });
}
