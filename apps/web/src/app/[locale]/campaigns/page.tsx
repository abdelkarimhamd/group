import type { Metadata } from "next";
import Link from "next/link";
import { Suspense } from "react";
import { getI18n } from "@/i18n/server";
import { getDictionary } from "@/i18n/dictionaries";
import { isLocale } from "@/i18n/config";
import { listCampaigns, listCategories, parseFilter, CAMPAIGN_FILTERS } from "@/lib/campaigns";
import { alternates } from "@/lib/site";
import { orNull } from "@/lib/api/server";
import { CampaignList } from "@/components/campaign-list";
import { CardGridSkeleton } from "@/components/skeletons";
import { LoadError } from "@/components/load-error";
import { Container, EmptyState, PageHeader, cx } from "@/components/ui";

export async function generateMetadata({ params }: PageProps<"/[locale]/campaigns">): Promise<Metadata> {
  const { locale } = await params;
  if (!isLocale(locale)) return {};
  const t = getDictionary(locale);
  return { title: t.campaigns.title, description: t.campaigns.intro, alternates: alternates(locale, "/campaigns") };
}

type Search = Promise<Record<string, string | string[] | undefined>>;

function one(value: string | string[] | undefined): string | undefined {
  return Array.isArray(value) ? value[0] : value;
}

function hrefFor(locale: string, filter: string, category?: string) {
  const params = new URLSearchParams();
  if (filter !== "open") params.set("filter", filter);
  if (category) params.set("category", category);
  const query = params.toString();
  return `/${locale}/campaigns${query ? `?${query}` : ""}`;
}

async function Filters({ searchParams }: { searchParams: Search }) {
  const { t, locale } = await getI18n();
  const search = await searchParams;
  const filter = parseFilter(one(search.filter));
  const category = one(search.category);
  const categories = (await orNull(listCategories(locale)))?.data ?? [];

  const chip = (active: boolean) =>
    cx(
      "inline-flex min-h-10 shrink-0 items-center rounded-full px-4 text-sm font-medium ring-1 transition",
      active ? "bg-brand-700 text-white ring-brand-700" : "bg-white text-slate-700 ring-slate-200 hover:ring-brand-300",
    );

  return (
    <div className="mb-8 space-y-3">
      <nav aria-label={t.campaigns.filterLabel} className="flex gap-2 overflow-x-auto pb-1">
        {CAMPAIGN_FILTERS.map((value) => (
          <Link
            key={value}
            href={hrefFor(locale, value, category)}
            aria-current={value === filter ? "page" : undefined}
            className={chip(value === filter)}
          >
            {t.campaigns.filters[value]}
          </Link>
        ))}
      </nav>
      {categories.length > 0 ? (
        <nav aria-label={t.campaigns.categoryLabel} className="flex gap-2 overflow-x-auto pb-1">
          <Link href={hrefFor(locale, filter)} aria-current={!category ? "page" : undefined} className={chip(!category)}>
            {t.campaigns.allCategories}
          </Link>
          {categories.map((item) => (
            <Link
              key={item.slug}
              href={hrefFor(locale, filter, item.slug)}
              aria-current={category === item.slug ? "page" : undefined}
              className={chip(category === item.slug)}
            >
              {item.name}
            </Link>
          ))}
        </nav>
      ) : null}
    </div>
  );
}

async function Results({ searchParams }: { searchParams: Search }) {
  const { t, locale } = await getI18n();
  const search = await searchParams;
  const filter = parseFilter(one(search.filter));
  const category = one(search.category);
  const page = await orNull(listCampaigns(locale, { filter, category }));
  if (!page) return <LoadError />;
  if (page.data.length === 0) return <EmptyState>{t.campaigns.empty[filter]}</EmptyState>;
  return (
    <CampaignList
      key={`${filter}:${category ?? ""}`}
      initial={page.data}
      nextCursor={page.meta.next_cursor}
      filter={filter}
      category={category}
    />
  );
}

export default async function CampaignsPage({ searchParams }: PageProps<"/[locale]/campaigns">) {
  const { t } = await getI18n();
  return (
    <Container className="py-10">
      <PageHeader title={t.campaigns.title} intro={t.campaigns.intro} />
      <Suspense fallback={<div className="mb-8 h-10" />}>
        <Filters searchParams={searchParams} />
      </Suspense>
      <Suspense fallback={<CardGridSkeleton count={6} />}>
        <Results searchParams={searchParams} />
      </Suspense>
    </Container>
  );
}
