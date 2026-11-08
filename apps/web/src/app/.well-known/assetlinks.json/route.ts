import { connection } from "next/server";
import { androidAssetLinks, appLinkResponse } from "@/lib/app-links";

// Android app links: read by Android, at this exact path, with no redirect.
// Built per request so ANDROID_CERT_SHA256 is read when the site runs, not at build.
export async function GET() {
  await connection();
  return appLinkResponse(androidAssetLinks());
}
