"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { syncPayment } from "@/actions/participations";
import { useI18n } from "@/i18n/client";
import { formatMoney, interpolate } from "@/i18n/format";
import type { Participation } from "@/lib/api/types";
import { paymentInTab } from "@/lib/payments";
import { RetryPayment } from "./payment-step";
import { Alert, ButtonLink, Card, Spinner } from "./ui";

type State =
  | { kind: "checking" }
  | { kind: "processing"; participation: Participation; gaveUp: boolean }
  | { kind: "success"; participation: Participation }
  | { kind: "failed"; participation: Participation }
  | { kind: "mismatch" }
  | { kind: "missing" }
  | { kind: "error"; network: boolean };

const RECHECK_MS = 3000;
const MAX_CHECKS = 10;
/** How long the page keeps asking when it gets no answer, before it says so. */
const GIVE_UP_AFTER_MS = 45_000;

export function PaymentReturn() {
  const { t, locale } = useI18n();
  const router = useRouter();
  const search = useSearchParams();
  const paymentId = search.get("payment_id");
  const providerPaymentId = search.get("id");
  const [state, setState] = useState<State>({ kind: "checking" });

  useEffect(() => {
    if (!providerPaymentId) return;
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let checks = 0;
    const started = Date.now();
    // Our ID from the redirect, else from this tab's form (the session cookie is the last resort).
    const ourPaymentId = paymentId ?? paymentInTab();

    async function check() {
      checks += 1;
      const result = await syncPayment({ locale, paymentId: ourPaymentId, providerPaymentId: providerPaymentId! }).catch(
        // The browser did not reach this site (a dropped connection, a deploy).
        () => ({ ok: false as const, status: 0, code: "network" }),
      );
      if (cancelled) return;
      if (!result.ok) {
        if (result.status === 401) {
          router.replace(
            `/${locale}/sign-in?expired=1&next=${encodeURIComponent(window.location.pathname + window.location.search)}`,
          );
        } else if (result.code === "provider_payment_mismatch") {
          setState({ kind: "mismatch" });
        } else if (result.status === 404) {
          setState({ kind: "missing" });
        } else if ([0, 429, 503].includes(result.status) && Date.now() - started < GIVE_UP_AFTER_MS) {
          // We or Moyasar did not answer this time: ask again shortly (as asked, when throttled).
          const wait = "retryAfter" in result && result.retryAfter ? result.retryAfter * 1000 : RECHECK_MS;
          timer = setTimeout(check, Math.min(Math.max(wait, RECHECK_MS), 15_000));
        } else {
          setState({ kind: "error", network: result.status === 0 });
        }
        return;
      }
      const participation = result.participation;
      if (participation.status === "authorized" || participation.status === "captured") {
        setState({ kind: "success", participation });
      } else if (participation.status === "pending_payment" && participation.payment?.status === "pending_authorization") {
        const gaveUp = checks >= MAX_CHECKS;
        setState({ kind: "processing", participation, gaveUp });
        if (!gaveUp) timer = setTimeout(check, RECHECK_MS);
      } else {
        setState({ kind: "failed", participation });
      }
    }

    void check();
    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [locale, paymentId, providerPaymentId, router]);

  const current: State = providerPaymentId ? state : { kind: "missing" };
  const joinHref = (participation: Participation) => `/${locale}/account/joins/${participation.id}`;
  const campaignHref = (participation: Participation) => `/${locale}/campaigns/${participation.campaign.id}`;

  return (
    <Card className="space-y-5" data-testid="payment-return" data-state={current.kind}>
      {current.kind === "checking" ? (
        <p className="flex items-center gap-3 text-slate-700" role="status">
          <Spinner />
          {t.payment.checking}
        </p>
      ) : null}

      {current.kind === "processing" ? (
        <>
          <div role="status">
            <h1 className="text-xl font-bold text-slate-900">{t.payment.processingTitle}</h1>
            <p className="mt-2 text-slate-600">{t.payment.processingBody}</p>
          </div>
          {current.gaveUp ? (
            <ButtonLink href={joinHref(current.participation)} variant="secondary">
              {t.payment.viewJoin}
            </ButtonLink>
          ) : (
            <Spinner className="text-brand-700" />
          )}
        </>
      ) : null}

      {current.kind === "success" ? (
        <>
          <div className="flex items-start gap-3" role="status">
            <span aria-hidden className="flex size-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-xl text-emerald-700">
              ✓
            </span>
            <div>
              <h1 className="text-xl font-bold text-slate-900">{t.payment.successTitle}</h1>
              <p className="mt-2 text-slate-600">
                {interpolate(t.payment.successBody, {
                  amount: formatMoney(current.participation.hold_amount, locale),
                })}
              </p>
            </div>
          </div>
          <div className="flex flex-col gap-2 sm:flex-row">
            <ButtonLink href={joinHref(current.participation)}>{t.payment.viewJoin}</ButtonLink>
            <ButtonLink href={campaignHref(current.participation)} variant="secondary">
              {t.payment.backToCampaign}
            </ButtonLink>
          </div>
        </>
      ) : null}

      {current.kind === "failed" ? (
        <>
          <div role="alert">
            <h1 className="text-xl font-bold text-slate-900">{t.payment.failedTitle}</h1>
            <p className="mt-2 text-slate-600">
              {current.participation.payment?.rejection_reason
                ? t.payment.reasons[current.participation.payment.rejection_reason]
                : t.payment.failedBody}
            </p>
          </div>
          {current.participation.status === "pending_payment" ? (
            <RetryPayment participation={current.participation} label={t.payment.tryAnotherCard} />
          ) : (
            <Alert tone="neutral">{t.joins.statusHelp[current.participation.status]}</Alert>
          )}
          <div className="flex flex-col gap-2 sm:flex-row">
            <ButtonLink href={joinHref(current.participation)} variant="secondary">
              {t.payment.viewJoin}
            </ButtonLink>
            <ButtonLink href={campaignHref(current.participation)} variant="ghost">
              {t.payment.backToCampaign}
            </ButtonLink>
          </div>
        </>
      ) : null}

      {current.kind === "mismatch" ? <Alert tone="danger">{t.payment.mismatch}</Alert> : null}
      {current.kind === "missing" ? <Alert tone="warning">{t.payment.missing}</Alert> : null}
      {current.kind === "error" ? (
        <Alert tone="danger">{current.network ? t.common.networkError : t.common.genericError}</Alert>
      ) : null}
      {current.kind === "mismatch" || current.kind === "missing" || current.kind === "error" ? (
        <ButtonLink href={`/${locale}/account/joins`} variant="secondary">
          {t.nav.myJoins}
        </ButtonLink>
      ) : null}
    </Card>
  );
}
