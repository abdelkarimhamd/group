import { connection } from "next/server";
import { appLinkResponse, appleAppSiteAssociation } from "@/lib/app-links";

// Universal links: read by iOS, at this exact path, with no redirect.
// Built per request so APPLE_TEAM_ID is read when the site runs, not at build.
export async function GET() {
  await connection();
  return appLinkResponse(appleAppSiteAssociation());
}
