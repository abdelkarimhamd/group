"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useI18n } from "@/i18n/client";
import { cx } from "./ui";

export function AccountNav() {
  const { t, locale } = useI18n();
  const pathname = usePathname();
  if (pathname.endsWith("/account/welcome")) return null;
  const base = `/${locale}/account`;
  const items = [
    { href: base, label: t.account.title, exact: true },
    { href: `${base}/joins`, label: t.account.sections.joins },
    { href: `${base}/orders`, label: t.account.sections.orders },
    { href: `${base}/addresses`, label: t.account.sections.addresses },
  ];
  return (
    <nav aria-label={t.account.title} className="-mx-4 mb-6 overflow-x-auto px-4 sm:mx-0 sm:px-0">
      <ul className="flex gap-2">
        {items.map((item) => {
          const active = item.exact ? pathname === item.href : pathname.startsWith(item.href);
          return (
            <li key={item.href}>
              <Link
                href={item.href}
                aria-current={active ? "page" : undefined}
                className={cx(
                  "inline-flex min-h-10 items-center whitespace-nowrap rounded-full px-4 text-sm font-medium ring-1 transition",
                  active ? "bg-brand-700 text-white ring-brand-700" : "bg-white text-slate-700 ring-slate-200 hover:bg-slate-50",
                )}
              >
                {item.label}
              </Link>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}
