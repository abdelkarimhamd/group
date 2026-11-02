import Link from "next/link";
import { Suspense } from "react";
import { getI18n } from "@/i18n/server";
import { getToken } from "@/lib/session";
import { LanguageSwitch, LanguageSwitchFallback } from "./language-switch";
import { Container } from "./ui";

async function AccountLink() {
  const { t, locale } = await getI18n();
  const signedIn = Boolean(await getToken());
  return signedIn ? (
    <Link
      href={`/${locale}/account`}
      className="inline-flex min-h-10 items-center gap-2 rounded-lg bg-brand-50 px-3 text-sm font-semibold text-brand-800 hover:bg-brand-100"
    >
      <svg aria-hidden viewBox="0 0 20 20" className="size-4 fill-current">
        <path d="M10 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 8a7 7 0 1 1 14 0H3Z" />
      </svg>
      {t.nav.account}
    </Link>
  ) : (
    <Link
      href={`/${locale}/sign-in`}
      className="inline-flex min-h-10 items-center rounded-lg bg-brand-700 px-3 text-sm font-semibold text-white hover:bg-brand-800"
    >
      {t.nav.signIn}
    </Link>
  );
}

export async function SiteHeader() {
  const { t, locale } = await getI18n();
  const links = [
    { href: `/${locale}/campaigns`, label: t.nav.campaigns },
    { href: `/${locale}/how-it-works`, label: t.nav.howItWorks },
  ];
  return (
    <header className="sticky top-0 z-30 border-b border-slate-200 bg-white/90 backdrop-blur">
      <Container className="flex h-16 items-center gap-3">
        <Link href={`/${locale}`} className="flex items-center gap-2 font-bold text-slate-900">
          <svg aria-hidden viewBox="0 0 64 64" className="size-8">
            <rect width="64" height="64" rx="14" fill="#0f6b5d" />
            <circle cx="24" cy="26" r="9" fill="#fff" />
            <circle cx="40" cy="26" r="9" fill="#93e9da" />
            <rect x="14" y="40" width="36" height="8" rx="4" fill="#f59e0b" />
          </svg>
          <span className="text-lg">{t.meta.siteName}</span>
        </Link>
        <nav aria-label={t.nav.primary} className="ms-4 hidden items-center gap-1 md:flex">
          {links.map((link) => (
            <Link
              key={link.href}
              href={link.href}
              className="rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 hover:text-slate-900"
            >
              {link.label}
            </Link>
          ))}
        </nav>
        <div className="ms-auto flex items-center gap-1 sm:gap-2">
          <Suspense fallback={<LanguageSwitchFallback />}>
            <LanguageSwitch />
          </Suspense>
          <Suspense fallback={<span className="inline-block h-10 w-24" />}>
            <AccountLink />
          </Suspense>
        </div>
      </Container>
      <nav aria-label={t.nav.primary} className="border-t border-slate-100 md:hidden">
        <Container className="flex gap-1 overflow-x-auto py-1">
          {links.map((link) => (
            <Link
              key={link.href}
              href={link.href}
              className="shrink-0 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
            >
              {link.label}
            </Link>
          ))}
        </Container>
      </nav>
    </header>
  );
}
