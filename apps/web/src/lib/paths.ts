import type { Locale } from "@/i18n/config";

/**
 * Only same-site paths are followed after sign-in, so a crafted link can't
 * send the customer to another site.
 */
export function safeNextPath(value: string | null | undefined, locale: Locale): string {
  const fallback = `/${locale}/account`;
  if (!value || !value.startsWith("/") || value.startsWith("//") || value.includes("\\")) {
    return fallback;
  }
  try {
    const url = new URL(value, "http://local.invalid");
    if (url.origin !== "http://local.invalid") return fallback;
    return `${url.pathname}${url.search}`;
  } catch {
    return fallback;
  }
}

/** The same path in another language: /ar/campaigns -> /en/campaigns. */
export function switchLocalePath(pathname: string, target: Locale): string {
  const rest = pathname.replace(/^\/(ar|en)(?=\/|$)/, "");
  return `/${target}${rest || ""}` || `/${target}`;
}
