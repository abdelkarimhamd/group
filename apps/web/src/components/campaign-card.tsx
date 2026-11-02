"use client";

import Link from "next/link";
import { useI18n } from "@/i18n/client";
import { formatDate, formatMoney, formatNumber, interpolate } from "@/i18n/format";
import { isSuccessStatus } from "@/lib/pricing";
import type { CampaignSummary } from "@/lib/api/types";
import { TimeUntil } from "./countdown";
import { CampaignImage, CampaignStatusBadge, DealConfirmedBadge, ProgressBar } from "./campaign-bits";

export function CampaignCard({ campaign }: { campaign: CampaignSummary }) {
  const { t, locale } = useI18n();
  const { progress } = campaign;
  const priceDrops = campaign.lowest_unit_price.amount_minor < campaign.current_unit_price.amount_minor;

  return (
    <article className="group relative flex flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 transition hover:shadow-md hover:ring-brand-200">
      <div className="relative aspect-[4/3] bg-slate-100">
        <CampaignImage image={campaign.image} className="size-full" />
        <div className="absolute start-3 top-3 flex flex-wrap gap-1.5">
          <CampaignStatusBadge status={campaign.status} />
          {progress.deal_confirmed_at && campaign.status === "active" ? <DealConfirmedBadge /> : null}
        </div>
      </div>
      <div className="flex flex-1 flex-col gap-3 p-4">
        <h3 className="line-clamp-2 text-base font-semibold text-slate-900">
          <Link href={`/${locale}/campaigns/${campaign.id}`} className="after:absolute after:inset-0 focus-visible:outline-none">
            {campaign.title}
          </Link>
        </h3>
        <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
          <span className="text-xl font-bold text-brand-800">
            {formatMoney(campaign.current_unit_price, locale)}
          </span>
          {priceDrops ? (
            <span className="text-sm text-slate-600">
              {interpolate(t.card.downTo, { price: formatMoney(campaign.lowest_unit_price, locale) })}
            </span>
          ) : null}
        </div>
        <div className="mt-auto flex flex-col gap-2">
          <ProgressBar progress={progress} size="sm" />
          <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-600">
            <span>
              {interpolate(t.card.progress, {
                committed: formatNumber(progress.committed_qty, locale),
                target: formatNumber(progress.min_target_qty, locale),
              })}
            </span>
            <span>
              {campaign.status === "scheduled" ? (
                interpolate(t.card.opensOn, { date: formatDate(campaign.starts_at, locale, "date") })
              ) : campaign.status === "active" ? (
                <TimeUntil iso={campaign.deadline_at} template={(time) => interpolate(t.card.endsIn, { time })} />
              ) : isSuccessStatus(campaign.status) || campaign.status === "failed" || campaign.status === "cancelled" ? (
                interpolate(t.card.endedOn, { date: formatDate(campaign.deadline_at, locale, "date") })
              ) : null}
            </span>
          </div>
        </div>
      </div>
    </article>
  );
}
