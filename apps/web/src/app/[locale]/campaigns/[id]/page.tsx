import type { Metadata } from "next";
import Link from "next/link";
import { Suspense } from "react";
import { getI18n } from "@/i18n/server";
import { getDictionary } from "@/i18n/dictionaries";
import { interpolate, ltr } from "@/i18n/format";
import { isLocale } from "@/i18n/config";
import { getCampaign } from "@/lib/campaigns";
import { handlePageError } from "@/lib/api/server";
import { ApiError } from "@/lib/api/errors";
import { alternates } from "@/lib/site";
import {
  CategoryLink,
  Gallery,
  JoinPanel,
  LiveBadges,
  LiveCampaignProvider,
  LivePrice,
  LiveProgressPanel,
  TierLadder,
} from "@/components/campaign-live";
import { DetailSkeleton } from "@/components/skeletons";
import { Card, Container } from "@/components/ui";

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export async function generateMetadata({ params }: PageProps<"/[locale]/campaigns/[id]">): Promise<Metadata> {
  const { locale, id } = await params;
  if (!isLocale(locale) || !UUID.test(id)) return {};
  try {
    const { data } = await getCampaign(locale, id);
    const t = getDictionary(locale);
    return {
      title: data.title,
      description: `${interpolate(t.campaign.code, { code: data.code })} · ${data.description.slice(0, 150)}`,
      alternates: alternates(locale, `/campaigns/${id}`),
      openGraph: data.image ? { images: [{ url: data.image.url, alt: data.image.alt ?? data.title }] } : undefined,
    };
  } catch {
    return {};
  }
}

async function CampaignView({ params }: { params: PageProps<"/[locale]/campaigns/[id]">["params"] }) {
  const { t, locale } = await getI18n();
  const { id } = await params;
  if (!UUID.test(id)) handlePageError(new ApiError(404), locale, "");

  let campaign;
  try {
    campaign = (await getCampaign(locale, id)).data;
  } catch (error) {
    handlePageError(error, locale, `/${locale}/campaigns/${id}`);
  }

  return (
    <LiveCampaignProvider campaignId={campaign.id} initial={campaign.progress}>
      <nav aria-label="breadcrumb" className="mb-4 flex flex-wrap items-center gap-2 text-sm text-slate-500">
        <Link href={`/${locale}/campaigns`} className="hover:text-slate-900 hover:underline">
          {t.nav.campaigns}
        </Link>
        {campaign.category ? (
          <>
            <span aria-hidden>/</span>
            <CategoryLink campaign={campaign} />
          </>
        ) : null}
      </nav>
      <div className="grid gap-8 lg:grid-cols-[1.1fr_1fr] lg:gap-12">
        <div className="space-y-8">
          <Gallery campaign={campaign} />
          <div className="hidden lg:block">
            <TierLadder campaign={campaign} />
          </div>
          {campaign.description ? (
            <section aria-labelledby="details-title" className="hidden lg:block">
              <h2 id="details-title" className="text-lg font-bold">
                {t.campaign.details}
              </h2>
              <p className="mt-3 whitespace-pre-line leading-relaxed text-slate-700">{campaign.description}</p>
            </section>
          ) : null}
        </div>

        <div className="space-y-6 lg:sticky lg:top-24 lg:self-start">
          <div className="space-y-3">
            <LiveBadges />
            <h1 className="text-2xl font-bold leading-snug text-slate-900 sm:text-3xl">{campaign.title}</h1>
            <p className="text-xs text-slate-500">
              {interpolate(t.campaign.code, { code: ltr(campaign.code) })}
            </p>
          </div>
          <LivePrice campaign={campaign} />
          <Card className="space-y-6">
            <LiveProgressPanel campaign={campaign} />
            <hr className="border-slate-100" />
            <JoinPanel campaign={campaign} />
          </Card>
          <div className="lg:hidden">
            <TierLadder campaign={campaign} />
          </div>
          {campaign.description ? (
            <section aria-labelledby="details-title-mobile" className="lg:hidden">
              <h2 id="details-title-mobile" className="text-lg font-bold">
                {t.campaign.details}
              </h2>
              <p className="mt-3 whitespace-pre-line leading-relaxed text-slate-700">{campaign.description}</p>
            </section>
          ) : null}
        </div>
      </div>
    </LiveCampaignProvider>
  );
}

export default function CampaignPage({ params }: PageProps<"/[locale]/campaigns/[id]">) {
  return (
    <Container className="py-8">
      <Suspense fallback={<DetailSkeleton />}>
        <CampaignView params={params} />
      </Suspense>
    </Container>
  );
}
