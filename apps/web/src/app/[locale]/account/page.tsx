import type { Metadata } from "next";
import Link from "next/link";
import { Suspense } from "react";
import { signOut } from "@/actions/auth";
import { getI18n } from "@/i18n/server";
import { getDictionary } from "@/i18n/dictionaries";
import { formatDate, interpolate } from "@/i18n/format";
import { isLocale } from "@/i18n/config";
import { accountApi, loadOrRedirect } from "@/lib/account";
import { PrivacyStatus, ProfileForm } from "@/components/profile-forms";
import { Button, Card, Skeleton } from "@/components/ui";

export async function generateMetadata({ params }: PageProps<"/[locale]/account">): Promise<Metadata> {
  const { locale } = await params;
  if (!isLocale(locale)) return {};
  return { title: getDictionary(locale).account.title, robots: { index: false } };
}

async function Overview() {
  const { t, locale } = await getI18n();
  const here = `/${locale}/account`;
  const api = await accountApi(locale, here);
  const { data: user } = await loadOrRedirect(api.GET("/me"), locale, here);

  const sections = [
    { href: `${here}/joins`, title: t.account.sections.joins, hint: t.account.joinsHint },
    { href: `${here}/orders`, title: t.account.sections.orders, hint: t.account.ordersHint },
    { href: `${here}/addresses`, title: t.account.sections.addresses, hint: t.account.addressesHint },
  ];

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-bold text-slate-900 sm:text-3xl" data-testid="account-greeting">
          {user.name ? interpolate(t.account.greeting, { name: user.name }) : t.account.greetingNoName}
        </h1>
        <p className="mt-1 text-sm text-slate-500">
          {interpolate(t.account.memberSince, { date: formatDate(user.created_at, locale, "date") })}
        </p>
      </div>

      <ul className="grid gap-4 sm:grid-cols-3">
        {sections.map((section) => (
          <li key={section.href}>
            <Link
              href={section.href}
              className="block h-full rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-brand-300"
            >
              <span className="font-semibold text-slate-900">{section.title}</span>
              <span className="mt-1 block text-sm text-slate-600">{section.hint}</span>
            </Link>
          </li>
        ))}
      </ul>

      <div className="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
        <Card>
          <h2 className="text-lg font-bold text-slate-900">{t.account.profileTitle}</h2>
          <p className="mt-1 mb-5 text-sm text-slate-600">{t.account.profileHint}</p>
          <ProfileForm user={user} />
        </Card>
        <div className="space-y-6">
          <Card>
            <h2 className="mb-3 text-lg font-bold text-slate-900">{t.account.privacy}</h2>
            <PrivacyStatus user={user} />
          </Card>
          <form action={signOut}>
            <input type="hidden" name="locale" value={locale} />
            <Button type="submit" variant="danger" className="w-full">
              {t.nav.signOut}
            </Button>
          </form>
        </div>
      </div>
    </div>
  );
}

export default function AccountPage() {
  return (
    <Suspense
      fallback={
        <div className="space-y-6">
          <Skeleton className="h-9 w-1/3" />
          <div className="grid gap-4 sm:grid-cols-3">
            <Skeleton className="h-24 rounded-2xl" />
            <Skeleton className="h-24 rounded-2xl" />
            <Skeleton className="h-24 rounded-2xl" />
          </div>
          <Skeleton className="h-80 rounded-2xl" />
        </div>
      }
    >
      <Overview />
    </Suspense>
  );
}
