"use client";

import Link from "next/link";
import { createContext, use, useEffect, useMemo, useState, type ReactNode } from "react";
import { useI18n } from "@/i18n/client";
import { formatDate, formatMoney, formatNumber, interpolate, plural } from "@/i18n/format";
import {
  currentTierIndex,
  estimateHold,
  isSuccessStatus,
  isTierReached,
  maxJoinQuantity,
} from "@/lib/pricing";
import type { CampaignDetail, CampaignProgress } from "@/lib/api/types";
import { CountdownBoxes } from "./countdown";
import {
  CampaignImage,
  CampaignStatusBadge,
  DealConfirmedBadge,
  ProgressBar,
  QuantityStepper,
  UnitsToTarget,
  VariantPicker,
} from "./campaign-bits";
import { Alert, ButtonLink, cx } from "./ui";

// The campaign page renders once on the server, then keeps its numbers live
// by polling GET /campaigns/{id}/progress (the same payload the API pushes
// over Reverb as progress.updated; a Reverb subscription can replace the
// poll later without touching the panels below).

const POLL_MS = 5000;
const LIVE_STATUSES = new Set(["scheduled", "active", "closing"]);

const ProgressContext = createContext<CampaignProgress | null>(null);

export function LiveCampaignProvider({
  campaignId,
  initial,
  children,
}: {
  campaignId: string;
  initial: CampaignProgress;
  children: ReactNode;
}) {
  const [progress, setProgress] = useState(initial);
  const live = LIVE_STATUSES.has(progress.status);

  useEffect(() => {
    if (!live) return;
    let stopped = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let controller: AbortController | undefined;

    async function poll() {
      if (document.visibilityState === "visible") {
        controller = new AbortController();
        try {
          const response = await fetch(`/api/v1/campaigns/${campaignId}/progress`, {
            headers: { Accept: "application/json" },
            cache: "no-store",
            signal: controller.signal,
          });
          if (response.ok) {
            const body = (await response.json()) as { data: CampaignProgress };
            if (!stopped) {
              setProgress((current) =>
                Date.parse(body.data.updated_at) >= Date.parse(current.updated_at) ? body.data : current,
              );
            }
          }
        } catch {
          // Keep the last numbers on screen; the next poll tries again.
        }
      }
      if (!stopped) timer = setTimeout(poll, POLL_MS);
    }

    timer = setTimeout(poll, POLL_MS);
    return () => {
      stopped = true;
      clearTimeout(timer);
      controller?.abort();
    };
  }, [campaignId, live]);

  return <ProgressContext value={progress}>{children}</ProgressContext>;
}

export function useProgress(): CampaignProgress {
  const progress = use(ProgressContext);
  if (!progress) throw new Error("useProgress must be used inside <LiveCampaignProvider>");
  return progress;
}

export function LiveBadges() {
  const progress = useProgress();
  const { t } = useI18n();
  const live = LIVE_STATUSES.has(progress.status);
  return (
    <div className="flex flex-wrap items-center gap-2">
      <CampaignStatusBadge status={progress.status} />
      {progress.deal_confirmed_at ? <DealConfirmedBadge /> : null}
      {live ? (
        <span className="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500">
          <span className="relative flex size-2">
            <span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75" />
            <span className="relative inline-flex size-2 rounded-full bg-emerald-500" />
          </span>
          {t.campaign.live}
        </span>
      ) : null}
    </div>
  );
}

export function LivePrice({ campaign }: { campaign: CampaignDetail }) {
  const progress = useProgress();
  const { t, locale } = useI18n();
  const success = isSuccessStatus(progress.status) && campaign.final_unit_price;
  const price = success ? campaign.final_unit_price! : progress.current_unit_price;
  const canDrop = !success && campaign.lowest_unit_price.amount_minor < price.amount_minor;
  return (
    <div>
      <p className="text-sm text-slate-600">{success ? t.campaign.finalPrice : t.campaign.currentPrice}</p>
      <p className="mt-1 flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <span className="text-3xl font-bold text-brand-800" data-testid="current-price">
          {formatMoney(price, locale)}
        </span>
        <span className="text-sm text-slate-500">
          {t.common.perUnit} · {t.common.vatIncluded}
        </span>
      </p>
      {canDrop ? (
        <p className="mt-1 text-sm font-medium text-deal-700">
          {interpolate(t.campaign.lowestPrice, { price: formatMoney(campaign.lowest_unit_price, locale) })}
        </p>
      ) : null}
    </div>
  );
}

export function LiveProgressPanel({ campaign }: { campaign: CampaignDetail }) {
  const progress = useProgress();
  const { t, locale } = useI18n();
  return (
    <div className="space-y-4">
      <div className="space-y-2">
        <ProgressBar progress={progress} />
        <div className="flex flex-wrap justify-between gap-2 text-sm">
          <span className="font-medium text-slate-900" data-testid="joined">
            {interpolate(t.campaign.joined, {
              committed: formatNumber(progress.committed_qty, locale),
              target: formatNumber(progress.min_target_qty, locale),
            })}
          </span>
          <span className="text-slate-600">
            {progress.remaining_qty > 0
              ? interpolate(t.campaign.remaining, { units: plural(t.common.units, progress.remaining_qty, locale) })
              : t.campaign.soldOut}
          </span>
        </div>
        <p className="text-sm text-slate-600">
          <UnitsToTarget progress={progress} />
        </p>
      </div>
      {progress.deal_confirmed_at && progress.status === "active" ? (
        <Alert tone="deal">{t.campaign.dealConfirmedHint}</Alert>
      ) : null}
      {progress.status === "active" || progress.status === "scheduled" ? (
        <CountdownBoxes
          iso={progress.status === "scheduled" ? campaign.starts_at : progress.deadline_at}
          label={progress.status === "scheduled" ? t.campaign.opensIn : t.campaign.endsIn}
        />
      ) : (
        <p className="text-sm text-slate-600">
          {interpolate(t.campaign.closedAt, { date: formatDate(progress.deadline_at, locale) })}
        </p>
      )}
      {campaign.original_deadline_at && campaign.original_deadline_at !== progress.deadline_at ? (
        <p className="text-xs text-slate-500">
          {interpolate(t.campaign.extendedFrom, { date: formatDate(campaign.original_deadline_at, locale) })}
        </p>
      ) : null}
    </div>
  );
}

export function TierLadder({ campaign }: { campaign: CampaignDetail }) {
  const progress = useProgress();
  const { t, locale } = useI18n();
  const tiers = campaign.tiers;
  const current = currentTierIndex(tiers, progress.committed_qty);
  const open = LIVE_STATUSES.has(progress.status);
  return (
    <section aria-labelledby="tiers-title">
      <h2 id="tiers-title" className="text-lg font-bold">
        {t.campaign.tiersTitle}
      </h2>
      <p className="mt-1 text-sm text-slate-600">{t.campaign.tiersHint}</p>
      <ol className="mt-4 space-y-2">
        {tiers.map((tier, index) => {
          const isCurrent = open && index === current;
          const reached = isTierReached(tier, progress.committed_qty);
          return (
            <li
              key={`${tier.min_qty}-${tier.max_qty}`}
              aria-current={isCurrent ? "step" : undefined}
              className={cx(
                "flex items-center justify-between gap-3 rounded-xl px-4 py-3 ring-1",
                isCurrent ? "bg-brand-50 ring-brand-300" : "bg-white ring-slate-200",
              )}
            >
              <div className="flex items-center gap-3">
                <span
                  aria-hidden
                  className={cx(
                    "flex size-6 items-center justify-center rounded-full text-xs font-bold",
                    reached ? "bg-brand-600 text-white" : "bg-slate-200 text-slate-600",
                  )}
                >
                  {reached ? "✓" : index + 1}
                </span>
                <span className="text-sm text-slate-700">
                  {interpolate(t.campaign.tierRange, {
                    min: formatNumber(tier.min_qty, locale),
                    max: formatNumber(tier.max_qty, locale),
                  })}
                </span>
                {isCurrent ? (
                  <span className="rounded-full bg-brand-700 px-2 py-0.5 text-xs font-semibold text-white">
                    {t.campaign.tierCurrent}
                  </span>
                ) : reached ? (
                  <span className="text-xs font-medium text-brand-700">{t.campaign.tierReached}</span>
                ) : null}
              </div>
              <span className={cx("font-bold", isCurrent ? "text-brand-800" : "text-slate-900")}>
                {formatMoney(tier.unit_price, locale)}
              </span>
            </li>
          );
        })}
      </ol>
    </section>
  );
}

export function JoinPanel({ campaign }: { campaign: CampaignDetail }) {
  const progress = useProgress();
  const { t, locale } = useI18n();
  const [variantId, setVariantId] = useState(campaign.variants[0]?.id ?? "");
  const max = maxJoinQuantity(progress.remaining_qty, campaign.per_customer_max_qty);
  const [rawQuantity, setQuantity] = useState(1);
  const quantity = Math.min(Math.max(1, rawQuantity), Math.max(1, max));

  const hold = useMemo(
    () => estimateHold(progress.current_unit_price, quantity),
    [progress.current_unit_price, quantity],
  );
  const lowest = useMemo(() => estimateHold(campaign.lowest_unit_price, quantity), [campaign.lowest_unit_price, quantity]);

  if (progress.status !== "active") {
    let message: string;
    if (progress.status === "scheduled") {
      message = interpolate(t.campaign.notJoinable.scheduled, { date: formatDate(campaign.starts_at, locale) });
    } else if (progress.status === "closing") {
      message = t.campaign.notJoinable.closing;
    } else if (isSuccessStatus(progress.status)) {
      message = campaign.final_unit_price
        ? interpolate(t.campaign.notJoinable.success, { price: formatMoney(campaign.final_unit_price, locale) })
        : t.campaign.notJoinable.successNoPrice;
    } else {
      message = t.campaign.notJoinable[progress.status as "failed" | "cancelled"];
    }
    return <Alert tone={progress.status === "scheduled" ? "brand" : "neutral"}>{message}</Alert>;
  }

  if (max < 1) {
    return <Alert tone="warning">{t.campaign.notJoinable.soldOut}</Alert>;
  }

  const params = new URLSearchParams({ variant: variantId, qty: String(quantity) });
  const canDrop = lowest.amount_minor < hold.amount_minor;

  return (
    <div className="space-y-5">
      {campaign.variants.length > 1 ? (
        <VariantPicker variants={campaign.variants} value={variantId} onChange={setVariantId} />
      ) : null}

      <QuantityStepper
        value={quantity}
        max={max}
        onChange={setQuantity}
        note={
          campaign.per_customer_max_qty
            ? interpolate(t.campaign.maxPerCustomer, {
                units: plural(t.common.units, campaign.per_customer_max_qty, locale),
              })
            : null
        }
      />

      <div className="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200">
        <div className="flex items-baseline justify-between gap-3">
          <span className="text-sm font-medium text-slate-700">{t.campaign.holdNow}</span>
          <span className="text-xl font-bold text-slate-900" data-testid="hold-estimate">
            {formatMoney(hold, locale)}
          </span>
        </div>
        <p className="mt-2 text-sm text-slate-600">{t.campaign.holdExplain}</p>
        {canDrop ? (
          <p className="mt-1 text-sm text-deal-700">
            {interpolate(t.campaign.mayBeLower, {
              amount: formatMoney(lowest, locale),
              price: formatMoney(campaign.lowest_unit_price, locale),
            })}
          </p>
        ) : null}
      </div>

      <ButtonLink href={`/${locale}/campaigns/${campaign.id}/join?${params}`} className="w-full" prefetch={false}>
        {t.campaign.join}
      </ButtonLink>
      <p className="text-center text-xs text-slate-500">{t.campaign.cancellationRule[campaign.cancellation_rule]}</p>
    </div>
  );
}

export function Gallery({ campaign }: { campaign: CampaignDetail }) {
  const { t } = useI18n();
  const images = campaign.images.length > 0 ? campaign.images : campaign.image ? [campaign.image] : [];
  const [active, setActive] = useState(0);
  const current = images[active];

  if (!current) {
    return (
      <div className="flex aspect-square items-center justify-center rounded-2xl bg-gradient-to-br from-brand-50 to-slate-100 text-slate-400">
        {t.campaign.noImage}
      </div>
    );
  }

  return (
    <section aria-label={t.campaign.gallery}>
      <div className="overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200">
        <CampaignImage image={current} alt={current.alt ?? campaign.title} eager className="aspect-square w-full" />
      </div>
      {images.length > 1 ? (
        <ul className="mt-3 flex gap-2 overflow-x-auto">
          {images.map((image, index) => (
            <li key={`${image.url}-${index}`}>
              <button
                type="button"
                onClick={() => setActive(index)}
                aria-label={interpolate(t.campaign.photo, { n: index + 1, total: images.length })}
                aria-pressed={index === active}
                className={cx(
                  "block size-16 overflow-hidden rounded-lg ring-2",
                  index === active ? "ring-brand-600" : "ring-transparent",
                )}
              >
                <CampaignImage image={image} alt="" sizes="sm" className="size-full" />
              </button>
            </li>
          ))}
        </ul>
      ) : null}
    </section>
  );
}

export function CategoryLink({ campaign }: { campaign: CampaignDetail }) {
  const { locale } = useI18n();
  if (!campaign.category) return null;
  return (
    <Link
      href={`/${locale}/campaigns?category=${encodeURIComponent(campaign.category.slug)}`}
      className="text-sm font-medium text-brand-700 hover:underline"
    >
      {campaign.category.name}
    </Link>
  );
}
