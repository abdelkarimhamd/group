import type { Metadata } from "next";
import { Suspense } from "react";
import { getI18n } from "@/i18n/server";
import { getDictionary } from "@/i18n/dictionaries";
import { isLocale } from "@/i18n/config";
import { accountApi, loadOrRedirect } from "@/lib/account";
import { OrderRow } from "@/components/join-summary";
import { ListSkeleton } from "@/components/skeletons";
import { ButtonLink, EmptyState, PageHeader } from "@/components/ui";

export async function generateMetadata({ params }: PageProps<"/[locale]/account/orders">): Promise<Metadata> {
  const { locale } = await params;
  if (!isLocale(locale)) return {};
  return { title: getDictionary(locale).orders.title, robots: { index: false } };
}

async function Orders({ searchParams }: Pick<PageProps<"/[locale]/account/orders">, "searchParams">) {
  const { t, locale } = await getI18n();
  const { cursor } = await searchParams;
  const here = `/${locale}/account/orders`;
  const api = await accountApi(locale, here);
  const { data, meta } = await loadOrRedirect(
    api.GET("/me/orders", { params: { query: { cursor: typeof cursor === "string" ? cursor : undefined, per_page: 20 } } }),
    locale,
    here,
  );

  if (data.length === 0) return <EmptyState>{t.orders.empty}</EmptyState>;
  return (
    <div className="space-y-4">
      <ul className="space-y-3">
        {data.map((order) => (
          <li key={order.id}>
            <OrderRow order={order} />
          </li>
        ))}
      </ul>
      {meta.next_cursor ? (
        <div className="text-center">
          <ButtonLink href={`${here}?cursor=${encodeURIComponent(meta.next_cursor)}`} variant="secondary">
            {t.common.loadMore}
          </ButtonLink>
        </div>
      ) : null}
    </div>
  );
}

export default async function OrdersPage({ searchParams }: PageProps<"/[locale]/account/orders">) {
  const { t } = await getI18n();
  return (
    <>
      <PageHeader title={t.orders.title} intro={t.orders.intro} />
      <Suspense fallback={<ListSkeleton />}>
        <Orders searchParams={searchParams} />
      </Suspense>
    </>
  );
}
