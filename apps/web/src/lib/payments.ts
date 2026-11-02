// Which payment form the join flow shows. "fake" sends the API's fake
// provider IDs (fake_authorized_<payment_id> / fake_declined_<payment_id>),
// which the API accepts only in its fake payment mode. "moyasar" will load
// Moyasar's hosted form once that integration lands.
export type PaymentsMode = "fake" | "moyasar";

export function paymentsMode(): PaymentsMode {
  return process.env.NEXT_PUBLIC_PAYMENTS_MODE === "moyasar" ? "moyasar" : "fake";
}

export function fakeProviderPaymentId(paymentId: string, outcome: "authorized" | "declined"): string {
  return `fake_${outcome}_${paymentId}`;
}
