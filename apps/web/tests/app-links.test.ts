import { describe, expect, it } from "vitest";
import { androidAssetLinks, appleAppSiteAssociation, appLinkResponse } from "@/lib/app-links";

const FINGERPRINT = Array.from({ length: 32 }, () => "AB").join(":");

describe("apple-app-site-association", () => {
  it("is missing until the Apple team ID is set", () => {
    expect(appleAppSiteAssociation({})).toBeNull();
    expect(appleAppSiteAssociation({ APPLE_TEAM_ID: "  " })).toBeNull();
  });

  it("opens deals and account pages in both languages, never payments", () => {
    const file = appleAppSiteAssociation({ APPLE_TEAM_ID: "ABCDE12345" });
    const detail = file?.applinks.details[0];
    expect(detail?.appIDs).toEqual(["ABCDE12345.sa.groupbuy.app"]);
    const paths = detail?.components.map((component) => component["/"]);
    expect(paths).toEqual(
      expect.arrayContaining(["/ar/campaigns/*", "/en/campaigns/*", "/ar/account/*", "/en/account", "/ar/campaigns"]),
    );
    expect(paths?.some((path) => path.includes("payments"))).toBe(false);
  });

  it("uses the bundle ID it is given", () => {
    const file = appleAppSiteAssociation({ APPLE_TEAM_ID: "ABCDE12345", IOS_BUNDLE_ID: "sa.example.app" });
    expect(file?.applinks.details[0]?.appIDs).toEqual(["ABCDE12345.sa.example.app"]);
  });
});

describe("assetlinks.json", () => {
  it("is missing until a valid signing fingerprint is set", () => {
    expect(androidAssetLinks({})).toBeNull();
    expect(androidAssetLinks({ ANDROID_CERT_SHA256: "not-a-fingerprint" })).toBeNull();
  });

  it("lists every fingerprint for the app's package", () => {
    const other = FINGERPRINT.replaceAll("AB", "01");
    const file = androidAssetLinks({ ANDROID_CERT_SHA256: `${FINGERPRINT.toLowerCase()}, ${other}` });
    expect(file).toEqual([
      {
        relation: ["delegate_permission/common.handle_all_urls"],
        target: { namespace: "android_app", package_name: "sa.groupbuy.app", sha256_cert_fingerprints: [FINGERPRINT, other] },
      },
    ]);
  });
});

describe("the response", () => {
  it("is JSON when configured and 404 when not", async () => {
    const ok = appLinkResponse({ a: 1 });
    expect(ok.status).toBe(200);
    expect(ok.headers.get("content-type")).toContain("application/json");
    expect(await ok.json()).toEqual({ a: 1 });
    expect(appLinkResponse(null).status).toBe(404);
  });
});
