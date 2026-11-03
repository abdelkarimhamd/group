"use client";

import { useEffect, useState } from "react";
import { useI18n } from "@/i18n/client";
import type { MoyasarCheckout } from "@/lib/api/types";
import { appBridge, parseAppCheckout, type AppBridge } from "@/lib/payments";
import { MoyasarForm } from "./moyasar-form";
import { Alert, Spinner } from "./ui";

type State =
  | { kind: "checking" }
  | { kind: "ready"; checkout: MoyasarCheckout; post: AppBridge }
  | { kind: "app-only" }
  | { kind: "invalid" };

/** The message the app receives as soon as Moyasar has created the payment. */
export type AppPaymentMessage = { type: "moyasar_payment"; id: string };

/**
 * The payment form for the mobile app. It covers the site's header and
 * footer. Opened in a browser it says to use the app; it shows the form only
 * where a React Native WebView's message bridge exists. That bridge is not
 * proof that the page is in our app, so the checkout is held to this site's
 * own Moyasar account and return page (parseAppCheckout): the most a shared
 * link can do is ask someone to pay one of the sender's own pending joins,
 * for its exact amount, until its reservation runs out.
 */
export function AppPaymentForm() {
  const { t } = useI18n();
  const [state, setState] = useState<State>({ kind: "checking" });

  useEffect(() => {
    const post = appBridge(window);
    const checkout = parseAppCheckout(window.location.hash, window.location.origin);
    // Read once: the fragment and the bridge exist only in the browser.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setState(!post ? { kind: "app-only" } : checkout ? { kind: "ready", checkout, post } : { kind: "invalid" });
  }, []);

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-white p-4" data-testid="app-payment" data-state={state.kind}>
      <div className="mx-auto w-full max-w-md">
        {state.kind === "checking" ? (
          <p className="flex items-center gap-3 text-sm text-slate-600" role="status">
            <Spinner />
            {t.payment.formLoading}
          </p>
        ) : null}
        {state.kind === "app-only" ? <Alert tone="warning">{t.payment.appOnly}</Alert> : null}
        {state.kind === "invalid" ? <Alert tone="danger">{t.payment.appInvalid}</Alert> : null}
        {state.kind === "ready" ? (
          <MoyasarForm
            checkout={state.checkout}
            record={async (id) => {
              const message: AppPaymentMessage = { type: "moyasar_payment", id };
              state.post(JSON.stringify(message));
            }}
          />
        ) : null}
      </div>
    </div>
  );
}
