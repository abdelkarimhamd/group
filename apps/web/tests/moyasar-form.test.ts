// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from "vitest";
import type { MoyasarCheckout } from "@/lib/api/types";
import { applePayLabel, moyasarFormOptions, RECORD_TIMEOUT_MS } from "@/lib/payments";

const checkout: MoyasarCheckout = {
  publishable_api_key: "pk_test_abc123",
  amount: 13_800,
  currency: "SAR",
  description: "GB-2611-0007 x2",
  callback_url: "https://example.sa/payments/return?payment_id=0198f5a2-0000-7000-8000-000000000001",
  methods: ["creditcard", "applepay"],
  supported_networks: ["mada", "visa", "mastercard"],
  manual: true,
  metadata: { payment_id: "0198f5a2-0000-7000-8000-000000000001" },
};

function options(record: (id: string) => Promise<unknown> = async () => undefined, element: HTMLElement | string = "#x") {
  return moyasarFormOptions(checkout, { element, locale: "ar", applePayLabel: "Group Buy", record });
}

afterEach(() => {
  vi.useRealTimers();
  document.body.innerHTML = "";
});

describe("Moyasar form options", () => {
  it("asks for an authorize-only hold where the form reads it", () => {
    const o = options();
    // The form ignores a top-level `manual`; a captured payment is voided by the API.
    expect(o.credit_card).toEqual({ manual: true });
    expect(o.apple_pay).toMatchObject({ manual: true, country: "SA", label: "Group Buy" });
    expect(o).not.toHaveProperty("manual");
  });

  it("passes the API's checkout through, metadata included, for the webhook", () => {
    const o = options();
    expect(o).toMatchObject({
      publishable_api_key: "pk_test_abc123",
      amount: 13_800,
      currency: "SAR",
      description: "GB-2611-0007 x2",
      callback_url: checkout.callback_url,
      methods: ["creditcard", "applepay"],
      supported_networks: ["mada", "visa", "mastercard"],
      metadata: { payment_id: checkout.metadata.payment_id },
      language: "ar",
    });
  });

  it("keeps Apple Pay's label to the ASCII the form accepts", () => {
    expect(applePayLabel("Jamaa Market")).toBe("Jamaa Market");
    expect(applePayLabel("سوق الجمعة")).toBe("Group Buy");
    expect(applePayLabel("  ")).toBe("Group Buy");
    expect(applePayLabel(undefined)).toBe("Group Buy");
  });

  it("records Moyasar's payment ID before the form moves on, and never blocks it", async () => {
    // The form refuses callbacks not declared async.
    expect(options().on_completed.constructor.name).toBe("AsyncFunction");

    const record = vi.fn(async () => undefined);
    await options(record).on_completed({ id: "pay_123", status: "initiated" });
    expect(record).toHaveBeenCalledWith("pay_123");

    // A failure is swallowed: the return page and the webhook settle the payment.
    await expect(options(async () => Promise.reject(new Error("offline"))).on_completed({ id: "pay_1", status: "initiated" })).resolves.toBeUndefined();

    // A call that never answers is cut short.
    vi.useFakeTimers();
    const pending = options(() => new Promise(() => {})).on_completed({ id: "pay_2", status: "initiated" });
    await vi.advanceTimersByTimeAsync(RECORD_TIMEOUT_MS);
    await expect(pending).resolves.toBeUndefined();
  });

  it("is accepted by Moyasar's own form, which renders the card fields", async () => {
    const host = document.createElement("div");
    document.body.appendChild(host);
    const { default: Moyasar } = await import("moyasar-payment-form");

    Moyasar.init(options(async () => undefined, host));
    await vi.waitFor(() => expect(host.querySelector("input")).not.toBeNull());

    // A configuration the form rejects renders this box instead of the fields.
    expect(host.textContent).not.toMatch(/configuration issue/i);
    expect(host.querySelectorAll("input").length).toBeGreaterThanOrEqual(3);
  });
});
