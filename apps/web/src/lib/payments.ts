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

const TAB_KEY = "gb_pending_payment";

/**
 * Remembers, in this tab only, which payment the open form is for, so the
 * return page still knows it if Moyasar's 3-D Secure redirect loses our
 * payment_id. Per tab, so two joins paid in two tabs never mix, and written
 * at once, before any redirect. Storage may be unavailable; then the
 * session's pending-payment cookie is the fallback.
 */
export function rememberPaymentInTab(paymentId: string, storage?: Pick<Storage, "setItem"> | null): void {
  try {
    // Reading window.sessionStorage itself throws where site data is blocked.
    (storage === undefined ? globalThis.sessionStorage : storage)?.setItem(TAB_KEY, paymentId);
  } catch {
    // Private mode or blocked storage.
  }
}

export function paymentInTab(storage?: Pick<Storage, "getItem"> | null): string | null {
  try {
    return (storage === undefined ? globalThis.sessionStorage : storage)?.getItem(TAB_KEY) || null;
  } catch {
    return null;
  }
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

/**
 * The mobile app's WebView: how the payment page inside it talks back to
 * the app. iOS WebViews with Apple Pay enabled get no injected script, so
 * there the page uses WebKit's message handler directly.
 */
export type AppBridge = (message: string) => void;

type BridgeWindow = {
  ReactNativeWebView?: { postMessage?: unknown };
  webkit?: { messageHandlers?: { ReactNativeWebView?: { postMessage?: unknown } } };
};

export function appBridge(win: unknown): AppBridge | null {
  const w = (win ?? {}) as BridgeWindow;
  const android = w.ReactNativeWebView;
  if (android && typeof android.postMessage === "function") {
    const post = android.postMessage as (message: string) => void;
    return (message) => post.call(android, message);
  }
  const ios = w.webkit?.messageHandlers?.ReactNativeWebView;
  if (ios && typeof ios.postMessage === "function") {
    const post = ios.postMessage as (message: string) => void;
    return (message) => post.call(ios, message);
  }
  return null;
}

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const PUBLISHABLE_KEY = /^pk_(test|live)_[A-Za-z0-9]+$/;
const METHODS = new Set(["creditcard", "applepay"]);
const NETWORKS = new Set(["mada", "visa", "mastercard"]);

function isStringList(value: unknown, allowed: Set<string>): value is string[] {
  return (
    Array.isArray(value) &&
    value.length > 0 &&
    value.every((item) => typeof item === "string" && allowed.has(item))
  );
}

/**
 * Reads the checkout the mobile app put in the page's URL fragment (the API's
 * MoyasarCheckout as JSON). The fragment never reaches a server. Anything
 * that is not a checkout the API could have made is refused: it must use this
 * site's own Moyasar account (`publishableKey`, the API's
 * MOYASAR_PUBLISHABLE_KEY; none set refuses everything) and come back to
 * this site's /payments/return for the same payment. A crafted link can
 * therefore never send a card payment or the redirect anywhere else.
 */
export function parseAppCheckout(
  hash: string,
  origin: string,
  publishableKey: string | undefined = process.env.NEXT_PUBLIC_MOYASAR_PUBLISHABLE_KEY,
): MoyasarCheckout | null {
  if (!publishableKey) return null;
  let value: unknown;
  try {
    value = JSON.parse(decodeURIComponent(hash.replace(/^#/, "")));
  } catch {
    return null;
  }
  if (!value || typeof value !== "object") return null;
  const c = value as Record<string, unknown>;
  const metadata = c.metadata as Record<string, unknown> | null | undefined;
  if (
    typeof c.publishable_api_key !== "string" ||
    !PUBLISHABLE_KEY.test(c.publishable_api_key) ||
    c.publishable_api_key !== publishableKey ||
    typeof c.amount !== "number" ||
    !Number.isSafeInteger(c.amount) ||
    c.amount < 100 ||
    c.currency !== "SAR" ||
    typeof c.description !== "string" ||
    c.manual !== true ||
    !isStringList(c.methods, METHODS) ||
    !isStringList(c.supported_networks, NETWORKS) ||
    !metadata ||
    typeof metadata !== "object" ||
    typeof metadata.payment_id !== "string" ||
    !UUID.test(metadata.payment_id) ||
    typeof c.callback_url !== "string"
  ) {
    return null;
  }
  let callback: URL;
  try {
    callback = new URL(c.callback_url);
  } catch {
    return null;
  }
  if (callback.origin !== origin || callback.pathname !== "/payments/return") return null;
  if (callback.searchParams.get("payment_id") !== metadata.payment_id) return null;
  return {
    publishable_api_key: c.publishable_api_key,
    amount: c.amount,
    currency: "SAR",
    description: c.description.slice(0, 255),
    callback_url: callback.toString(),
    methods: c.methods as MoyasarCheckout["methods"],
    supported_networks: c.supported_networks as MoyasarCheckout["supported_networks"],
    manual: true,
    metadata: { payment_id: metadata.payment_id },
  };
}
