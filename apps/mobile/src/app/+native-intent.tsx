import { appPathForWebsiteLink } from '@/lib/links';

/**
 * Links to the website that the system hands to the app (universal links on iOS, app links on
 * Android; see app.config.ts) open the matching screen, so a shared deal opens in the app.
 * Every other link, such as the app's own groupbuy:// links, goes through unchanged.
 */
export function redirectSystemPath({ path }: { path: string; initial: boolean }): string {
  try {
    return appPathForWebsiteLink(path) ?? path;
  } catch {
    return path;
  }
}
