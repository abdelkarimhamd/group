"use client";

import Link from "next/link";
import { useI18n } from "@/i18n/client";
import { formatDate, formatMoney, interpolate, plural, ltr } from "@/i18n/format";
import type { Order, Participation } from "@/lib/api/types";
import { OrderStatusBadge, ParticipationStatusBadge } from "./account-bits";
import { CampaignImage } from "./campaign-bits";

/** One row in "My joins". */
export function JoinRow({ participation }: { participation: Participation }) {
  const { t, locale } = useI18n();
  const amount = participation.capture_amount ?? participation.hold_amount;
  return (
    <Link
      href={`/${locale}/account/joins/${participation.id}`}
      className="flex gap-4 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 transition hover:ring-brand-300"
      data-testid="join-row"
    >
      <CampaignImage image={participation.campaign.image} sizes="sm" className="size-20 shrink-0 rounded-xl" />
      <div className="min-w-0 flex-1 space-y-1">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <p className="font-semibold text-slate-900">{participation.campaign.title}</p>
          <ParticipationStatusBadge status={participation.status} />
        </div>
        <p className="text-sm text-slate-600">
          {participation.product_variant.name} · {plural(t.common.units, participation.quantity, locale)}
        </p>
        <div className="flex flex-wrap items-baseline justify-between gap-2 text-sm">
          <span className="font-semibold text-slate-900">
            {formatMoney(amount, locale)}
            <span className="ms-1 text-xs font-normal text-slate-500">
              {participation.capture_amount ? t.joins.chargedAmount : t.joins.holdAmount}
            </span>
          </span>
          <span className="text-xs text-slate-500">
            {interpolate(t.joins.joinedOn, { date: formatDate(participation.created_at, locale) })}
          </span>
        </div>
      </div>
    </Link>
  );
}

/** One row in "My orders". */
export function OrderRow({ order }: { order: Order }) {
  const { t, locale } = useI18n();
  return (
    <Link
      href={`/${locale}/account/orders/${order.id}`}
      className="flex gap-4 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 transition hover:ring-brand-300"
      data-testid="order-row"
    >
      <CampaignImage image={order.campaign.image} sizes="sm" className="size-20 shrink-0 rounded-xl" />
      <div className="min-w-0 flex-1 space-y-1">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <p className="font-semibold text-slate-900">{order.campaign.title}</p>
          <OrderStatusBadge status={order.status} />
        </div>
        <p className="text-sm text-slate-600">
          {interpolate(t.orders.number, { number: ltr(order.order_number) })}
        </p>
        <div className="flex flex-wrap items-baseline justify-between gap-2 text-sm">
          <span className="font-semibold text-slate-900">{formatMoney(order.total, locale)}</span>
          <span className="text-xs text-slate-500">
            {interpolate(t.orders.placedOn, { date: formatDate(order.created_at, locale) })}
          </span>
        </div>
      </div>
    </Link>
  );
}
