"use client";

import Link from "next/link";
import { usePathname, useSearchParams } from "next/navigation";
import { useI18n } from "@/i18n/client";
import { otherLocale } from "@/i18n/config";
import { switchLocalePath } from "@/lib/paths";

const className =
  "inline-flex min-h-10 items-center rounded-lg px-3 text-sm font-semibold text-slate-700 hover:bg-slate-100";

/** Same page, other language. Wrapped in Suspense by the header (search params). */
export function LanguageSwitch() {
  const { t, locale } = useI18n();
  const pathname = usePathname();
  const search = useSearchParams().toString();
  const target = otherLocale(locale);
  const href = `${switchLocalePath(pathname, target)}${search ? `?${search}` : ""}`;
  return (
    <Link href={href} hrefLang={target} lang={target} aria-label={t.nav.switchLanguageLabel} className={className}>
      {t.nav.switchLanguage}
    </Link>
  );
}

export function LanguageSwitchFallback() {
  const { t, locale } = useI18n();
  const target = otherLocale(locale);
  return (
    <Link href={`/${target}`} hrefLang={target} lang={target} className={className}>
      {t.nav.switchLanguage}
    </Link>
  );
}
