"use client";

import { useCallback, useState } from "react";
import { useI18n } from "@/i18n/client";
import { formatNumber, interpolate, plural } from "@/i18n/format";
import { progressPercents } from "@/lib/pricing";
import type { CampaignProgress, CampaignStatus, Image, Variant } from "@/lib/api/types";
import { Badge, cx } from "./ui";

const statusTone = {
  scheduled: "brand",
  active: "success",
  closing: "warning",
  purchasing: "deal",
  fulfilling: "deal",
  completed: "deal",
  failed: "neutral",
  cancelled: "neutral",
} as const satisfies Record<CampaignStatus, Parameters<typeof Badge>[0]["tone"]>;

export function CampaignStatusBadge({ status }: { status: CampaignStatus }) {
  const { t } = useI18n();
  return <Badge tone={statusTone[status]}>{t.campaignStatus[status]}</Badge>;
}

export function DealConfirmedBadge() {
  const { t } = useI18n();
  return (
    <Badge tone="deal">
      <svg aria-hidden viewBox="0 0 20 20" className="size-3.5 fill-current">
        <path d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.6l7.3-7.3a1 1 0 0 1 1.4 0Z" />
      </svg>
      {t.campaign.dealConfirmed}
    </Badge>
  );
}

/**
 * Committed units against the maximum, with a marker at the target. Only
 * authorized holds count (spec: progress counts committed units only).
 */
export function ProgressBar({
  progress,
  size = "md",
}: {
  progress: Pick<CampaignProgress, "committed_qty" | "min_target_qty" | "max_qty" | "deal_confirmed_at">;
  size?: "sm" | "md";
}) {
  const { t, locale } = useI18n();
  const { committed, target } = progressPercents(progress);
  const confirmed = Boolean(progress.deal_confirmed_at);
  return (
    <div
      role="progressbar"
      aria-label={t.campaign.progressLabel}
      aria-valuemin={0}
      aria-valuemax={progress.max_qty}
      aria-valuenow={progress.committed_qty}
      aria-valuetext={interpolate(t.campaign.joined, {
        committed: formatNumber(progress.committed_qty, locale),
        target: formatNumber(progress.min_target_qty, locale),
      })}
      className={cx("relative w-full rounded-full bg-slate-200", size === "sm" ? "h-2" : "h-3")}
    >
      <div
        className={cx(
          "absolute inset-y-0 start-0 rounded-full transition-[width] duration-700",
          confirmed ? "bg-deal-500" : "bg-brand-600",
        )}
        style={{ width: `${committed}%` }}
      />
      <div
        aria-hidden
        title={t.campaign.target}
        className="absolute -inset-y-1 w-0.5 rounded bg-slate-700"
        style={{ insetInlineStart: `${target}%` }}
      />
    </div>
  );
}

export function UnitsToTarget({ progress }: { progress: CampaignProgress }) {
  const { t, locale } = useI18n();
  if (progress.deal_confirmed_at || progress.units_to_target <= 0) return null;
  return (
    <span>
      {interpolate(t.campaign.toTarget, {
        units: plural(t.common.units, progress.units_to_target, locale),
      })}
    </span>
  );
}

/** Product photo with a quiet placeholder when the image is missing or fails. */
export function CampaignImage({
  image,
  className,
  sizes = "md",
  alt,
  eager = false,
}: {
  image: Pick<Image, "url" | "alt"> | null | undefined;
  className?: string;
  sizes?: "sm" | "md";
  alt?: string;
  eager?: boolean;
}) {
  const { t } = useI18n();
  const url = image?.url;
  const [failedUrl, setFailedUrl] = useState<string | null>(null);
  const failed = failedUrl !== null && failedUrl === url;
  // An image that failed before React hydrated never fires onError again,
  // so check its state once the element is attached.
  const checkLoaded = useCallback(
    (img: HTMLImageElement | null) => {
      if (img && img.complete && img.naturalWidth === 0 && url) setFailedUrl(url);
    },
    [url],
  );
  if (!image || failed) {
    return (
      <div
        className={cx(
          "flex items-center justify-center bg-gradient-to-br from-brand-50 to-slate-100 text-slate-400",
          className,
        )}
      >
        <svg aria-hidden viewBox="0 0 24 24" className={sizes === "sm" ? "size-8" : "size-12"} fill="none" stroke="currentColor" strokeWidth="1.5">
          <path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5v-9Z" />
          <path d="m3 7.5 9 4.5 9-4.5M12 12v9" />
        </svg>
        <span className="sr-only">{t.campaign.noImage}</span>
      </div>
    );
  }
  return (
    // Product photos come from the API's CDN, whose host differs per
    // environment, so a plain <img> is used rather than next/image.
    // eslint-disable-next-line @next/next/no-img-element
    <img
      ref={checkLoaded}
      src={image.url}
      alt={alt ?? image.alt ?? ""}
      loading={eager ? "eager" : "lazy"}
      decoding="async"
      onError={() => setFailedUrl(image.url)}
      className={cx("object-cover", className)}
    />
  );
}

/** Option chips for a campaign with more than one variant. */
export function VariantPicker({
  variants,
  value,
  onChange,
  name = "variant",
}: {
  variants: Pick<Variant, "id" | "name">[];
  value: string;
  onChange: (id: string) => void;
  name?: string;
}) {
  const { t } = useI18n();
  return (
    <fieldset>
      <legend className="text-sm font-medium text-slate-800">{t.campaign.variant}</legend>
      <div className="mt-2 flex flex-wrap gap-2">
        {variants.map((variant) => (
          <label
            key={variant.id}
            className={cx(
              "inline-flex min-h-10 cursor-pointer items-center rounded-xl px-4 text-sm font-medium ring-1 transition has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-brand-600",
              variant.id === value ? "bg-brand-700 text-white ring-brand-700" : "bg-white text-slate-700 ring-slate-300",
            )}
          >
            <input
              type="radio"
              name={name}
              value={variant.id}
              checked={variant.id === value}
              onChange={() => onChange(variant.id)}
              className="sr-only"
            />
            {variant.name}
          </label>
        ))}
      </div>
    </fieldset>
  );
}

/** − 2 + stepper, bounded by 1 and `max`. */
export function QuantityStepper({
  value,
  max,
  onChange,
  note,
  disabled = false,
}: {
  value: number;
  max: number;
  onChange: (value: number) => void;
  note?: string | null;
  disabled?: boolean;
}) {
  const { t, locale } = useI18n();
  return (
    <div>
      <span id="qty-label" className="text-sm font-medium text-slate-800">
        {t.campaign.quantity}
      </span>
      <div className="mt-2 flex items-center gap-3">
        <div role="group" aria-labelledby="qty-label" className="inline-flex items-center rounded-xl ring-1 ring-slate-300">
          <button
            type="button"
            aria-label={t.campaign.decrease}
            onClick={() => onChange(value - 1)}
            disabled={disabled || value <= 1}
            className="flex size-11 items-center justify-center text-xl text-slate-700 disabled:text-slate-300"
          >
            −
          </button>
          <output aria-live="polite" className="w-10 text-center text-lg font-semibold tabular-nums" data-testid="quantity">
            {formatNumber(value, locale)}
          </output>
          <button
            type="button"
            aria-label={t.campaign.increase}
            onClick={() => onChange(value + 1)}
            disabled={disabled || value >= max}
            className="flex size-11 items-center justify-center text-xl text-slate-700 disabled:text-slate-300"
          >
            +
          </button>
        </div>
        {note ? <span className="text-xs text-slate-500">{note}</span> : null}
      </div>
    </div>
  );
}
