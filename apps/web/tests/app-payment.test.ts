import { describe, expect, it, vi } from "vitest";
import type { MoyasarCheckout } from "@/lib/api/types";
import { appBridge, parseAppCheckout, paymentInTab, rememberPaymentInTab } from "@/lib/payments";

const ORIGIN = "https://example.sa";
const PAYMENT_ID = "0198f5a2-0000-7000-8000-000000000001";

const checkout: MoyasarCheckout = {
  publishable_api_key: "pk_test_abc123",
  amount: 13_800,
  currency: "SAR",
  description: "GB-2611-0007 x2",
  callback_url: `${ORIGIN}/payments/return?payment_id=${PAYMENT_ID}`,
  methods: ["creditcard", "applepay"],
  supported_networks: ["mada", "visa", "mastercard"],
  manual: true,
  metadata: { payment_id: PAYMENT_ID },
};

// How the app builds the fragment (apps/mobile/src/payments/MoyasarPaymentSheet.tsx).
const hashOf = (value: unknown) => `#${encodeURIComponent(JSON.stringify(value))}`;

describe("the app's checkout in the URL fragment", () => {
  it("reads a checkout the API made", () => {
    expect(parseAppCheckout(hashOf(checkout), ORIGIN)).toEqual(checkout);
  });

  it("keeps only the fields the form needs", () => {
    const parsed = parseAppCheckout(hashOf({ ...checkout, extra: "x", metadata: { ...checkout.metadata, x: 1 } }), ORIGIN);
    expect(parsed).toEqual(checkout);
  });

  it.each([
    ["no fragment", ""],
    ["not JSON", "#not-json"],
    ["broken escapes", "#%E0%A4%A"],
    ["JSON that is not an object", hashOf([1, 2])],
  ])("refuses %s", (_name, hash) => {
    expect(parseAppCheckout(hash, ORIGIN)).toBeNull();
  });

  it.each<[string, Record<string, unknown>]>([
    ["a secret key", { publishable_api_key: "sk_test_abc123" }],
    ["a fractional amount", { amount: 138.5 }],
    ["an amount under one riyal", { amount: 99 }],
    ["another currency", { currency: "USD" }],
    ["a payment captured at once", { manual: false }],
    ["an unknown method", { methods: ["creditcard", "stcpay"] }],
    ["no methods", { methods: [] }],
    ["an unknown network", { supported_networks: ["amex"] }],
    ["no payment ID", { metadata: {} }],
    ["a payment ID that is not a UUID", { metadata: { payment_id: "1" } }],
    ["a return to another site", { callback_url: `https://evil.example/payments/return?payment_id=${PAYMENT_ID}` }],
    ["a return over plain http", { callback_url: `http://example.sa/payments/return?payment_id=${PAYMENT_ID}` }],
    ["a return to another page", { callback_url: `${ORIGIN}/account?payment_id=${PAYMENT_ID}` }],
    ["a return for another payment", { callback_url: `${ORIGIN}/payments/return?payment_id=0198f5a2-0000-7000-8000-000000000002` }],
    ["a return that is not a URL", { callback_url: "payments/return" }],
  ])("refuses %s", (_name, change) => {
    expect(parseAppCheckout(hashOf({ ...checkout, ...change }), ORIGIN)).toBeNull();
  });
});

describe("the bridge to the app", () => {
  it("is missing in an ordinary browser", () => {
    expect(appBridge({})).toBeNull();
    expect(appBridge(undefined)).toBeNull();
    expect(appBridge({ ReactNativeWebView: {} })).toBeNull();
  });

  it("uses the script the app injects (Android)", () => {
    const target = { postMessage: vi.fn(function (this: unknown, _m: string) { expect(this).toBe(target); }) };
    appBridge({ ReactNativeWebView: target })!("hello");
    expect(target.postMessage).toHaveBeenCalledWith("hello");
  });

  it("uses WebKit's message handler when nothing is injected (iOS with Apple Pay)", () => {
    const target = { postMessage: vi.fn(function (this: unknown, _m: string) { expect(this).toBe(target); }) };
    appBridge({ webkit: { messageHandlers: { ReactNativeWebView: target } } })!("hello");
    expect(target.postMessage).toHaveBeenCalledWith("hello");
  });
});

describe("the payment remembered in the tab", () => {
  it("is kept per tab for the return page", () => {
    const store = new Map<string, string>();
    const storage = { setItem: (k: string, v: string) => void store.set(k, v), getItem: (k: string) => store.get(k) ?? null };
    expect(paymentInTab(storage)).toBeNull();
    rememberPaymentInTab(PAYMENT_ID, storage);
    expect(paymentInTab(storage)).toBe(PAYMENT_ID);
  });

  it("never breaks the page when storage is blocked", () => {
    const blocked = {
      setItem: () => {
        throw new Error("SecurityError");
      },
      getItem: () => {
        throw new Error("SecurityError");
      },
    };
    expect(() => rememberPaymentInTab(PAYMENT_ID, blocked)).not.toThrow();
    expect(paymentInTab(blocked)).toBeNull();
    expect(paymentInTab(undefined)).toBeNull();
  });
});
