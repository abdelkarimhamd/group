import type { Metadata } from "next";
import { locales, type Locale } from "@/i18n/config";

export function siteUrl(): string {
  return (process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000").replace(/\/+$/, "");
}

/** Canonical URL and the other-language alternate for a path like "/campaigns". */
export function alternates(locale: Locale, path: string): Metadata["alternates"] {
  const suffix = path === "/" ? "" : path;
  return {
    canonical: `/${locale}${suffix}`,
    languages: Object.fromEntries(locales.map((l) => [l === "ar" ? "ar-SA" : "en-SA", `/${l}${suffix}`])),
  };
}
