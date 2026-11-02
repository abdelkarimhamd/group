"use client";

import type { AddressInput, OrderStatus, ParticipationStatus, PaymentStatus } from "@/lib/api/types";
import { useI18n } from "@/i18n/client";
import { formatSaudiMobile } from "@/lib/phone";
import { Badge } from "./ui";

type Tone = "neutral" | "brand" | "deal" | "success" | "warning" | "danger";

const participationTone: Record<ParticipationStatus, Tone> = {
  pending_payment: "warning",
  authorized: "brand",
  payment_failed: "danger",
  reservation_expired: "neutral",
  cancelled: "neutral",
  released: "neutral",
  captured: "success",
  capture_failed: "danger",
};

const orderTone: Record<OrderStatus, Tone> = {
  confirmed: "brand",
  processing: "brand",
  ready_to_ship: "brand",
  shipped: "brand",
  delivered: "success",
  completed: "success",
  cancelled: "neutral",
  return_requested: "warning",
  returned: "neutral",
};

const paymentTone: Record<PaymentStatus, Tone> = {
  pending_authorization: "warning",
  authorized: "brand",
  failed: "danger",
  voided: "neutral",
  expired: "neutral",
  captured: "success",
  capture_failed: "danger",
  partially_refunded: "neutral",
  refunded: "neutral",
};

export function ParticipationStatusBadge({ status }: { status: ParticipationStatus }) {
  const { t } = useI18n();
  return <Badge tone={participationTone[status]}>{t.joins.status[status]}</Badge>;
}

export function OrderStatusBadge({ status }: { status: OrderStatus }) {
  const { t } = useI18n();
  return <Badge tone={orderTone[status]}>{t.orders.status[status]}</Badge>;
}

export function PaymentStatusBadge({ status }: { status: PaymentStatus }) {
  const { t } = useI18n();
  return <Badge tone={paymentTone[status]}>{t.paymentStatus[status]}</Badge>;
}

/** A Saudi National Address on two or three short lines. */
export function AddressLines({ address, showRecipient = true }: { address: AddressInput; showRecipient?: boolean }) {
  const { locale } = useI18n();
  const comma = locale === "ar" ? "، " : ", ";
  return (
    <div className="text-sm leading-relaxed text-slate-700">
      {showRecipient ? (
        <p className="font-medium text-slate-900">
          {address.recipient_name}
          <span className="mx-2 font-normal text-slate-500" dir="ltr">
            {formatSaudiMobile(address.recipient_phone)}
          </span>
        </p>
      ) : null}
      <p>
        <span className="ltr-nums">{address.building_no}</span> {address.street}
        {comma}
        {address.district}
      </p>
      <p>
        {address.city}{" "}
        <span dir="ltr">
          {address.postal_code}
          {address.additional_no ? `-${address.additional_no}` : ""}
        </span>
        {address.short_address ? (
          <span className="mx-2 text-slate-500" dir="ltr">
            {address.short_address}
          </span>
        ) : null}
      </p>
    </div>
  );
}
