import type { Metadata } from "next";
import Link from "next/link";
import { Suspense } from "react";
import { connection } from "next/server";
import { getI18n } from "@/i18n/server";
import { getDictionary } from "@/i18n/dictionaries";
import { isLocale } from "@/i18n/config";
import { listCampaigns, listCategories } from "@/lib/campaigns";
import { alternates } from "@/lib/site";
import { orNull } from "@/lib/api/server";
import { CampaignGrid } from "@/components/campaign-list";
import { CardGridSkeleton } from "@/components/skeletons";
import { LoadError } from "@/components/load-error";
import { ButtonLink, Container, EmptyState } from "@/components/ui";
import { HowSteps } from "@/components/how-steps";

export async function generateMetadata({ params }: PageProps<"/[locale]">): Promise<Metadata> {
  const { locale } = await params;
  if (!isLocale(locale)) return {};
  const t = getDictionary(locale);
  return { alternates: alternates(locale, "/"), description: t.meta.description };
}

async function OpenCampaigns() {
  await connection();
  const { t, locale } = await getI18n();
  const page = await orNull(listCampaigns(locale, { filter: "open", perPage: 6 }));
  if (!page) return <LoadError />;
  if (page.data.length === 0) return <EmptyState>{t.home.emptyOpen}</EmptyState>;
  return <CampaignGrid campaigns={page.data} />;
}

async function UpcomingCampaigns() {
  await connection();
  const { t, locale } = await getI18n();
  const page = await orNull(listCampaigns(locale, { filter: "upcoming", perPage: 3 }));
  if (!page || page.data.length === 0) return null;
  return (
    <section className="mt-16">
      <div className="mb-6 flex items-end justify-between gap-4">
        <h2 className="text-2xl font-bold">{t.home.upcomingTitle}</h2>
        <Link href={`/${locale}/campaigns?filter=upcoming`} className="text-sm font-semibold text-brand-700 hover:underline">
          {t.common.viewAll}
        </Link>
      </div>
      <CampaignGrid campaigns={page.data} />
    </section>
  );
}

async function Categories() {
  await connection();
  const { t, locale } = await getI18n();
  const categories = await orNull(listCategories(locale));
  const top = categories?.data.filter((category) => !category.parent_id) ?? [];
  if (top.length === 0) return null;
  return (
    <section className="mt-16">
      <h2 className="mb-4 text-2xl font-bold">{t.home.categoriesTitle}</h2>
      <ul className="flex flex-wrap gap-2">
        {top.map((category) => (
          <li key={category.id}>
            <Link
              href={`/${locale}/campaigns?category=${encodeURIComponent(category.slug)}`}
              className="inline-flex min-h-10 items-center rounded-full bg-white px-4 text-sm font-medium text-slate-700 ring-1 ring-slate-200 hover:ring-brand-300"
            >
              {category.name}
            </Link>
          </li>
        ))}
      </ul>
    </section>
  );
}

export default async function HomePage() {
  const { t, locale } = await getI18n();
  return (
    <>
      <section className="bg-gradient-to-b from-brand-50 to-slate-50">
        <Container className="py-12 sm:py-20">
          <div className="max-w-2xl">
            <p className="text-sm font-semibold text-brand-700">{t.meta.tagline}</p>
            <h1 className="mt-3 text-3xl font-bold leading-tight text-slate-900 sm:text-5xl">{t.home.heroTitle}</h1>
            <p className="mt-4 text-lg leading-relaxed text-slate-700">{t.home.heroBody}</p>
            <div className="mt-8 flex flex-wrap gap-3">
              <ButtonLink href={`/${locale}/campaigns`}>{t.home.heroCta}</ButtonLink>
              <ButtonLink href={`/${locale}/how-it-works`} variant="secondary">
                {t.home.heroSecondary}
              </ButtonLink>
            </div>
          </div>
        </Container>
      </section>

      <Container className="py-12">
        <section>
          <div className="mb-6 flex items-end justify-between gap-4">
            <h2 className="text-2xl font-bold">{t.home.openTitle}</h2>
            <Link href={`/${locale}/campaigns`} className="text-sm font-semibold text-brand-700 hover:underline">
              {t.common.viewAll}
            </Link>
          </div>
          <Suspense fallback={<CardGridSkeleton count={3} />}>
            <OpenCampaigns />
          </Suspense>
        </section>

        <section className="mt-16 grid gap-4 sm:grid-cols-3">
          {t.home.trust.map((item) => (
            <div key={item.title} className="rounded-2xl bg-white p-5 ring-1 ring-slate-200">
              <h3 className="font-semibold text-slate-900">{item.title}</h3>
              <p className="mt-2 text-sm leading-relaxed text-slate-600">{item.body}</p>
            </div>
          ))}
        </section>

        <section className="mt-16">
          <h2 className="mb-6 text-2xl font-bold">{t.how.title}</h2>
          <HowSteps steps={t.how.steps} />
        </section>

        <Suspense fallback={null}>
          <UpcomingCampaigns />
        </Suspense>
        <Suspense fallback={null}>
          <Categories />
        </Suspense>
      </Container>
    </>
  );
}
