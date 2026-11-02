import type { Metadata } from "next";
import { Suspense } from "react";
import { getI18n } from "@/i18n/server";
import { getDictionary } from "@/i18n/dictionaries";
import { isLocale } from "@/i18n/config";
import { accountApi, loadOrRedirect } from "@/lib/account";
import { AddressBook } from "@/components/address-book";
import { PageHeader } from "@/components/ui";
import { ListSkeleton } from "@/components/skeletons";

export async function generateMetadata({ params }: PageProps<"/[locale]/account/addresses">): Promise<Metadata> {
  const { locale } = await params;
  if (!isLocale(locale)) return {};
  return { title: getDictionary(locale).addresses.title, robots: { index: false } };
}

async function Addresses() {
  const { locale } = await getI18n();
  const here = `/${locale}/account/addresses`;
  const api = await accountApi(locale, here);
  const [addresses, me] = await Promise.all([
    loadOrRedirect(api.GET("/me/addresses"), locale, here),
    loadOrRedirect(api.GET("/me"), locale, here),
  ]);
  return <AddressBook addresses={addresses.data} defaults={{ name: me.data.name, phone: me.data.phone }} />;
}

export default async function AddressesPage() {
  const { t } = await getI18n();
  return (
    <>
      <PageHeader title={t.addresses.title} intro={t.addresses.intro} />
      <Suspense fallback={<ListSkeleton />}>
        <Addresses />
      </Suspense>
    </>
  );
}
