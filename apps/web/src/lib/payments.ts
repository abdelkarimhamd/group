import type { Locale } from "@/i18n/config";
import type { MoyasarCheckout } from "@/lib/api/types";

// Which payment form the join flow shows. "fake" sends the API's fake
// provider IDs (fake_authorized_<payment_id> / fake_declined_<payment_id>),
// which the API accepts only in its fake payment mode. "moyasar" mounts
// Moyasar's payment form (moyasar-payment-form) with the API's checkout.
export type PaymentsMode = "fake" | "moyasar";

export function paymentsMode(): PaymentsMode {
  return process.env.NEXT_PUBLIC_PAYMENTS_MODE === "moyasar" ? "moyasar" : "fake";
}

export function fakeProviderPaymentId(paymentId: string, outcome: "authorized" | "declined"): string {
  return `fake_${outcome}_${paymentId}`;
}

const ASCII = /^[\x20-\x7E]+$/;

/**
 * The merchant name on the Apple Pay sheet. Moyasar's form refuses anything
 * but printable ASCII there, so an Arabic or empty setting falls back.
 */
export function applePayLabel(value = process.env.NEXT_PUBLIC_APPLE_PAY_LABEL): string {
  const label = value?.trim() ?? "";
  return ASCII.test(label) ? label : "Group Buy";
}

/** The payment Moyasar's API created, as the form hands it to on_completed. */
export type MoyasarFormPayment = { id: string; status: string };

/**
 * The options of `Moyasar.init` this site uses (moyasar-payment-form 2.3.0
 * ships no types for them). Checked against the form's own validation in
 * tests/moyasar-form.test.ts.
 */
export type MoyasarFormOptions = {
  element: HTMLElement | string;
  publishable_api_key: string;
  amount: number;
  currency: string;
  description: string;
  callback_url: string;
  methods: string[];
  supported_networks: string[];
  metadata: Record<string, string>;
  language: Locale;
  credit_card: { manual: boolean };
  apple_pay: { country: string; label: string; validate_merchant_url: string; manual: boolean };
  on_completed: (payment: MoyasarFormPayment) => Promise<void>;
};

/** How long the form may wait for us to record the payment before it moves on to 3-D Secure. */
export const RECORD_TIMEOUT_MS = 8000;

/**
 * Builds the form's options from the API's checkout. The API's top-level
 * `manual` (authorize only) must reach the form as `credit_card.manual` and
 * `apple_pay.manual`: the form ignores a top-level `manual`, and a payment it
 * captures at once is voided and rejected by the API.
 *
 * `record` receives Moyasar's payment ID as soon as the payment exists,
 * before any 3-D Secure redirect, so the API can settle the hold even if
 * the customer never comes back. The form waits for it, so it is cut short
 * after RECORD_TIMEOUT_MS, and a failure never stops the payment.
 */
export function moyasarFormOptions(
  checkout: MoyasarCheckout,
  context: {
    element: HTMLElement | string;
    locale: Locale;
    applePayLabel: string;
    record: (providerPaymentId: string) => Promise<unknown>;
  },
): MoyasarFormOptions {
  return {
    element: context.element,
    publishable_api_key: checkout.publishable_api_key,
    amount: checkout.amount,
    currency: checkout.currency,
    description: checkout.description,
    callback_url: checkout.callback_url,
    methods: [...checkout.methods],
    supported_networks: [...checkout.supported_networks],
    metadata: { ...checkout.metadata },
    language: context.locale,
    credit_card: { manual: checkout.manual },
    apple_pay: {
      country: "SA",
      label: applePayLabel(context.applePayLabel),
      validate_merchant_url: "https://api.moyasar.com/v1/applepay/initiate",
      manual: checkout.manual,
    },
    // The form accepts only functions declared async.
    on_completed: async (payment) => {
      if (!payment?.id) return;
      let timer: ReturnType<typeof setTimeout> | undefined;
      try {
        await Promise.race([
          context.record(payment.id),
          new Promise((resolve) => {
            timer = setTimeout(resolve, RECORD_TIMEOUT_MS);
          }),
        ]);
      } catch {
        // The return page and Moyasar's webhook settle the payment anyway.
      } finally {
        if (timer) clearTimeout(timer);
      }
    },
  };
}
