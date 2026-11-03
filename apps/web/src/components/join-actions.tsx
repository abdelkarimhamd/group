"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { cancelParticipation } from "@/actions/participations";
import { useI18n } from "@/i18n/client";
import type { Participation, Payment } from "@/lib/api/types";
import { PaymentStep, RetryPayment } from "./payment-step";
import { Alert, Button, ButtonLink, Spinner } from "./ui";

const RETRYABLE = new Set(["failed", "voided", "expired"]);

/** What the customer can do next with one join. */
export function JoinActions({ participation }: { participation: Participation }) {
  const { t, locale } = useI18n();
  const router = useRouter();
  const [confirming, setConfirming] = useState(false);
  const [continuing, setContinuing] = useState(false);
  // A new attempt started here: its form stays put when the page refreshes to it.
  const [retried, setRetried] = useState<Payment | null>(null);
  const [notice, setNotice] = useState<{ tone: "success" | "danger"; text: string } | null>(null);
  const [pending, startTransition] = useTransition();
  const payment = participation.payment;

  function cancel() {
    setNotice(null);
    startTransition(async () => {
      const result = await cancelParticipation({ locale, participationId: participation.id });
      setConfirming(false);
      if (result.ok) {
        setNotice({ tone: "success", text: t.joins.cancelled });
        return;
      }
      if (result.status === 401) {
        router.replace(`/${locale}/sign-in?expired=1&next=${encodeURIComponent(window.location.pathname)}`);
        return;
      }
      setNotice({
        tone: "danger",
        text:
          result.code === "cancellation_not_allowed"
            ? t.joins.cancelNotAllowed
            : result.status === 0
              ? t.common.networkError
              : t.common.genericError,
      });
    });
  }

  const awaitingCard = participation.status === "pending_payment" && payment?.status === "pending_authorization";
  const canRetry = participation.status === "pending_payment" && (!payment || RETRYABLE.has(payment.status));

  return (
    <div className="space-y-4" data-testid="join-actions">
      {notice ? <Alert tone={notice.tone}>{notice.text}</Alert> : null}

      {retried && participation.status === "pending_payment" ? (
        <PaymentStep participation={participation} payment={retried} />
      ) : awaitingCard && payment ? (
        continuing ? (
          <PaymentStep participation={participation} payment={payment} />
        ) : (
          <Button onClick={() => setContinuing(true)}>{t.joins.continuePayment}</Button>
        )
      ) : null}

      {canRetry && !retried ? (
        <RetryPayment participation={participation} label={t.joins.retryPayment} onStarted={setRetried} />
      ) : null}

      <div className="flex flex-wrap gap-2">
        {participation.order_id ? (
          <ButtonLink href={`/${locale}/account/orders/${participation.order_id}`}>{t.joins.viewOrder}</ButtonLink>
        ) : null}
        <ButtonLink href={`/${locale}/campaigns/${participation.campaign.id}`} variant="secondary">
          {t.joins.viewCampaign}
        </ButtonLink>
        {participation.can_cancel && !confirming ? (
          <Button variant="danger" onClick={() => setConfirming(true)} data-testid="cancel-join">
            {t.joins.cancel}
          </Button>
        ) : null}
      </div>

      {confirming ? (
        <div role="alertdialog" aria-labelledby="cancel-title" className="space-y-3 rounded-xl bg-rose-50 p-4 ring-1 ring-rose-200">
          <p id="cancel-title" className="font-semibold text-rose-900">
            {t.joins.cancelConfirmTitle}
          </p>
          <p className="text-sm text-rose-900">{t.joins.cancelConfirmBody}</p>
          <div className="flex flex-wrap gap-2">
            <Button variant="danger" onClick={cancel} disabled={pending} data-testid="confirm-cancel">
              {pending ? <Spinner /> : null}
              {t.joins.cancelYes}
            </Button>
            <Button variant="ghost" onClick={() => setConfirming(false)} disabled={pending}>
              {t.joins.cancelKeep}
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  );
}
