"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { retryPayment } from "@/actions/participations";
import { useI18n } from "@/i18n/client";
import { formatMoney, interpolate } from "@/i18n/format";
import { useNow } from "@/hooks/use-now";
import { fakeProviderPaymentId, paymentsMode } from "@/lib/payments";
import type { Participation, Payment } from "@/lib/api/types";
import { TimeUntil } from "./countdown";
import { Alert, Button, ButtonLink, Card, Spinner } from "./ui";

type JoinRef = Pick<Participation, "id" | "hold_amount" | "reservation_expires_at"> & {
  campaign: Pick<Participation["campaign"], "id">;
};

/**
 * The card hold. Moyasar's hosted form goes here once that integration lands
 * (third-party work comes last); until then the fake mode sends the API's
 * fake provider IDs through the same return page Moyasar will use.
 */
export function PaymentStep({ participation, payment }: { participation: JoinRef; payment: Payment }) {
  const { t, locale } = useI18n();
  const now = useNow();
  const expired = now !== null && Date.parse(participation.reservation_expires_at) <= now;

  if (expired) {
    return (
      <Card className="space-y-4">
        <Alert tone="warning">{t.join.reservationExpired}</Alert>
        <ButtonLink href={`/${locale}/campaigns/${participation.campaign.id}`} variant="secondary">
          {t.join.startAgain}
        </ButtonLink>
      </Card>
    );
  }

  const returnHref = (outcome: "authorized" | "declined") =>
    `/${locale}/payments/return?${new URLSearchParams({
      payment_id: payment.id,
      id: fakeProviderPaymentId(payment.id, outcome),
    })}`;

  return (
    <Card className="space-y-5" data-testid="payment-step">
      <div>
        <h2 className="text-lg font-bold text-slate-900">{t.join.payTitle}</h2>
        <p className="mt-1 text-sm text-slate-600">{t.join.payBody}</p>
      </div>
      <div className="flex items-baseline justify-between gap-3 rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200">
        <span className="text-sm font-medium text-slate-700">{t.join.holdAmount}</span>
        <span className="text-xl font-bold text-slate-900">{formatMoney(payment.amount, locale)}</span>
      </div>
      <Alert tone="warning">
        <TimeUntil
          iso={participation.reservation_expires_at}
          template={(time) => interpolate(t.join.reservedFor, { time })}
        />
      </Alert>

      {paymentsMode() === "fake" ? (
        <div className="space-y-3 rounded-xl border border-dashed border-brand-300 p-4">
          <p className="font-semibold text-slate-900">{t.payment.fakeTitle}</p>
          <p className="text-sm text-slate-600">{t.payment.fakeBody}</p>
          <div className="flex flex-col gap-2 sm:flex-row">
            <ButtonLink href={returnHref("authorized")} prefetch={false} className="sm:flex-1">
              {t.payment.fakeApprove}
            </ButtonLink>
            <ButtonLink href={returnHref("declined")} prefetch={false} variant="secondary" className="sm:flex-1">
              {t.payment.fakeDecline}
            </ButtonLink>
          </div>
        </div>
      ) : (
        <Alert tone="neutral">{t.payment.providerPending}</Alert>
      )}
    </Card>
  );
}

/** "Try another card": a fresh attempt for the same reserved units. */
export function RetryPayment({ participation, label }: { participation: JoinRef; label: string }) {
  const { t, locale } = useI18n();
  const router = useRouter();
  const [payment, setPayment] = useState<Payment | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  if (payment) return <PaymentStep participation={participation} payment={payment} />;

  function retry() {
    setError(null);
    startTransition(async () => {
      const result = await retryPayment({
        locale,
        participationId: participation.id,
        campaignId: participation.campaign.id,
      });
      if (result.ok) {
        setPayment(result.payment);
        return;
      }
      if (result.status === 401) {
        router.replace(`/${locale}/sign-in?expired=1&next=${encodeURIComponent(`/${locale}/account/joins/${participation.id}`)}`);
        return;
      }
      if (result.code === "already_paid") {
        // The hold went through meanwhile: show the join as it now stands.
        setError(t.joins.retryAlreadyPaid);
        router.refresh();
        return;
      }
      if (result.code === "reservation_expired") setError(t.joins.retryExpired);
      else if (result.code === "payment_in_progress") setError(t.joins.retryInProgress);
      else if (result.status === 0) setError(t.common.networkError);
      else setError(t.common.genericError);
    });
  }

  return (
    <div className="space-y-3">
      <Button onClick={retry} disabled={pending}>
        {pending ? <Spinner /> : null}
        {label}
      </Button>
      {error ? <Alert tone="danger">{error}</Alert> : null}
    </div>
  );
}
