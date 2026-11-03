"use client";

import "moyasar-payment-form/dist/moyasar.css";
import { useEffect, useRef, useState } from "react";
import { recordPayment } from "@/actions/participations";
import { useI18n } from "@/i18n/client";
import type { MoyasarCheckout } from "@/lib/api/types";
import { applePayLabel, moyasarFormOptions } from "@/lib/payments";
import { Alert, Spinner } from "./ui";

/**
 * Moyasar's payment form (cards, and Apple Pay where the browser offers it).
 * Card details go straight from the form to Moyasar; this site only learns
 * Moyasar's payment ID, records it with the API, and lets the form take the
 * customer through 3-D Secure and back to /payments/return.
 */
export function MoyasarForm({
  checkout,
  paymentId,
  participationId,
  campaignId,
}: {
  checkout: MoyasarCheckout;
  paymentId: string;
  participationId: string;
  campaignId: string;
}) {
  const { t, locale } = useI18n();
  const container = useRef<HTMLDivElement>(null);
  const [state, setState] = useState<"loading" | "ready" | "failed">("loading");
  // A fresh checkout (a new attempt) needs a fresh form: the form keeps the
  // options of its first render, and has no way to be taken down.
  const key = `${paymentId}:${checkout.amount}:${checkout.callback_url}`;

  useEffect(() => {
    const parent = container.current;
    if (!parent) return;
    let cancelled = false;
    const host = document.createElement("div");
    parent.appendChild(host);
    setState("loading");

    import("moyasar-payment-form")
      .then(({ default: Moyasar }) => {
        if (cancelled) return;
        Moyasar.init(
          moyasarFormOptions(checkout, {
            element: host,
            locale,
            applePayLabel: applePayLabel(),
            record: (providerPaymentId) =>
              recordPayment({ locale, paymentId, participationId, campaignId, providerPaymentId }),
          }),
        );
        setState("ready");
      })
      .catch(() => {
        if (!cancelled) setState("failed");
      });

    return () => {
      cancelled = true;
      host.remove();
    };
    // `key` covers the checkout; the IDs are part of it.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, locale]);

  return (
    <div data-testid="moyasar-form" data-state={state}>
      {state === "loading" ? (
        <p className="flex items-center gap-3 text-sm text-slate-600" role="status">
          <Spinner />
          {t.payment.formLoading}
        </p>
      ) : null}
      {state === "failed" ? <Alert tone="danger">{t.payment.formFailed}</Alert> : null}
      <div ref={container} dir={locale === "ar" ? "rtl" : "ltr"} />
    </div>
  );
}
