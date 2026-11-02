import Link from "next/link";
import { getI18n } from "@/i18n/server";
import { interpolate } from "@/i18n/format";
import { Container } from "./ui";

export async function SiteFooter() {
  const { t, locale } = await getI18n();
  const links = [
    { href: `/${locale}/how-it-works`, label: t.nav.howItWorks },
    { href: `/${locale}/privacy`, label: t.footer.privacy },
    { href: `/${locale}/terms`, label: t.footer.terms },
    { href: `/${locale}/returns`, label: t.footer.returns },
  ];
  return (
    <footer className="mt-16 border-t border-slate-200 bg-white">
      <Container className="flex flex-col gap-4 py-8 text-sm text-slate-600 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <p className="font-semibold text-slate-900">{t.meta.siteName}</p>
          <p className="mt-1">{t.footer.vatNote}</p>
          <p className="mt-1 text-xs text-slate-500">
            {interpolate(t.footer.rights, { siteName: t.meta.siteName })}
          </p>
        </div>
        <nav className="flex flex-wrap gap-x-4 gap-y-2">
          {links.map((link) => (
            <Link key={link.href} href={link.href} className="hover:text-slate-900 hover:underline">
              {link.label}
            </Link>
          ))}
        </nav>
      </Container>
    </footer>
  );
}
