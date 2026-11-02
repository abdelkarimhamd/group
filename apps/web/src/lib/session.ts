import "server-only";
import { cookies } from "next/headers";

// The API token never reaches browser JavaScript: it lives in an httpOnly
// cookie, and every authenticated call goes through Server Components or
// Server Actions on this server.
export const SESSION_COOKIE = "gb_session";
// The payment the customer is about to finish on the payment form, so the
// return page can find it even if the callback URL carries no payment_id.
export const PENDING_PAYMENT_COOKIE = "gb_pending_payment";

const THIRTY_DAYS = 60 * 60 * 24 * 30;

export async function getToken(): Promise<string | undefined> {
  return (await cookies()).get(SESSION_COOKIE)?.value || undefined;
}

export async function setSession(token: string): Promise<void> {
  (await cookies()).set(SESSION_COOKIE, token, {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax",
    path: "/",
    maxAge: THIRTY_DAYS,
  });
}

export async function clearSession(): Promise<void> {
  const store = await cookies();
  store.delete(SESSION_COOKIE);
  store.delete(PENDING_PAYMENT_COOKIE);
}

export type PendingPayment = { paymentId: string; participationId: string; campaignId: string };

export async function setPendingPayment(value: PendingPayment): Promise<void> {
  (await cookies()).set(PENDING_PAYMENT_COOKIE, JSON.stringify(value), {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax",
    path: "/",
    maxAge: 60 * 60,
  });
}

export async function getPendingPayment(): Promise<PendingPayment | undefined> {
  const raw = (await cookies()).get(PENDING_PAYMENT_COOKIE)?.value;
  if (!raw) return undefined;
  try {
    const value = JSON.parse(raw) as Partial<PendingPayment>;
    if (value.paymentId && value.participationId && value.campaignId) {
      return value as PendingPayment;
    }
  } catch {
    // A tampered or old cookie is simply ignored.
  }
  return undefined;
}
