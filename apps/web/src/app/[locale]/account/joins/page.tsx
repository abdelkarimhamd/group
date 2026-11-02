import type { Metadata } from "next";
import { Suspense } from "react";
import { getI18n } from "@/i18n/server";
import { getDictionary } from "@/i18n/dictionaries";
import { isLocale } from "@/i18n/config";
import { accountApi, loadOrRedirect } from "@/lib/account";
import { JoinRow } from "@/components/join-summary";
import { ListSkeleton } from "@/components/skeletons";
import { ButtonLink, EmptyState, PageHeader } from "@/components/ui";

export async function generateMetadata({ params }: PageProps<"/[locale]/account/joins">): Promise<Metadata> {
  const { locale } = await params;
  if (!isLocale(locale)) return {};
  return { title: getDictionary(locale).joins.title, robots: { index: false } };
}

async function Joins({ searchParams }: Pick<PageProps<"/[locale]/account/joins">, "searchParams">) {
  const { t, locale } = await getI18n();
  const { cursor } = await searchParams;
  const here = `/${locale}/account/joins`;
  const api = await accountApi(locale, here);
  const { data, meta } = await loadOrRedirect(
    api.GET("/me/participations", {
      params: { query: { cursor: typeof cursor === "string" ? cursor : undefined, per_page: 20 } },
    }),
    locale,
    here,
  );

  if (data.length === 0) {
    return (
      <EmptyState action={<ButtonLink href={`/${locale}/campaigns`}>{t.joins.emptyCta}</ButtonLink>}>
        {t.joins.empty}
      </EmptyState>
    );
  }
  return (
    <div className="space-y-4">
      <ul className="space-y-3">
        {data.map((participation) => (
          <li key={participation.id}>
            <JoinRow participation={participation} />
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

export default async function JoinsPage({ searchParams }: PageProps<"/[locale]/account/joins">) {
  const { t } = await getI18n();
  return (
    <>
      <PageHeader title={t.joins.title} intro={t.joins.intro} />
      <Suspense fallback={<ListSkeleton />}>
        <Joins searchParams={searchParams} />
      </Suspense>
    </>
  );
}
