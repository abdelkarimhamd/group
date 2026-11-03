"use server";

import { refresh } from "next/cache";
import { isLocale, type Locale } from "@/i18n/config";
import { createApi, unwrap } from "@/lib/api/server";
import { toActionFailure, type ActionFailure } from "@/lib/api/errors";
import type { Participation, Payment } from "@/lib/api/types";
import { getPendingPayment, getToken, setPendingPayment } from "@/lib/session";

async function authed(localeValue: string) {
  const locale: Locale = isLocale(localeValue) ? localeValue : "ar";
  const token = await getToken();
  return { locale, api: token ? createApi(locale, token) : null };
}

const signedOut: ActionFailure = { ok: false, status: 401, code: "unauthenticated" };

/**
 * Reserves units and opens a payment attempt. The client keeps the same
 * client_request_id across retries of one attempt, so a lost response
 * never joins twice (the API answers 201 with the original join).
 */
export async function joinCampaign(input: {
  locale: string;
  campaignId: string;
  variantId: string;
  quantity: number;
  shownUnitPriceHalalas: number;
  addressId: string;
  clientRequestId: string;
}): Promise<{ ok: true; participation: Participation } | ActionFailure> {
  const { api } = await authed(input.locale);
  if (!api) return signedOut;
  try {
    const { data } = await unwrap(
      api.POST("/campaigns/{campaign_id}/participations", {
        params: { path: { campaign_id: input.campaignId } },
        body: {
          client_request_id: input.clientRequestId,
          product_variant_id: input.variantId,
          quantity: input.quantity,
          shown_unit_price_halalas: input.shownUnitPriceHalalas,
          delivery_address_id: input.addressId,
        },
      }),
    );
    if (data.payment) {
      await setPendingPayment({
        paymentId: data.payment.id,
        participationId: data.id,
        campaignId: data.campaign.id,
      });
    }
    return { ok: true, participation: data };
  } catch (error) {
    return toActionFailure(error);
  }
}

/** A new payment attempt after a declined card, while the reservation lasts. */
export async function retryPayment(input: {
  locale: string;
  participationId: string;
  campaignId: string;
}): Promise<{ ok: true; payment: Payment } | ActionFailure> {
  const { api } = await authed(input.locale);
  if (!api) return signedOut;
  try {
    const { data } = await unwrap(
      api.POST("/me/participations/{participation_id}/payments", {
        params: { path: { participation_id: input.participationId } },
      }),
    );
    await setPendingPayment({
      paymentId: data.id,
      participationId: input.participationId,
      campaignId: input.campaignId,
    });
    return { ok: true, payment: data };
  } catch (error) {
    return toActionFailure(error);
  }
}

/**
 * Called by Moyasar's form as soon as the payment exists, and by the payment
 * return page. The API fetches the payment from the provider itself; the
 * redirect's own status parameter is never trusted.
 */
export async function syncPayment(input: {
  locale: string;
  paymentId?: string | null;
  providerPaymentId: string;
}): Promise<{ ok: true; participation: Participation } | ActionFailure> {
  const { api } = await authed(input.locale);
  if (!api) return signedOut;
  const paymentId = input.paymentId || (await getPendingPayment())?.paymentId;
  if (!paymentId || !input.providerPaymentId) {
    return { ok: false, status: 404, code: "payment_missing" };
  }
  try {
    const { data } = await unwrap(
      api.POST("/payments/{payment_id}/sync", {
        params: { path: { payment_id: paymentId } },
        body: { provider_payment_id: input.providerPaymentId.slice(0, 64) },
      }),
    );
    return { ok: true, participation: data };
  } catch (error) {
    return toActionFailure(error);
  }
}

export async function cancelParticipation(input: {
  locale: string;
  participationId: string;
}): Promise<{ ok: true; participation: Participation } | ActionFailure> {
  const { api } = await authed(input.locale);
  if (!api) return signedOut;
  try {
    const { data } = await unwrap(
      api.POST("/me/participations/{participation_id}/cancel", {
        params: { path: { participation_id: input.participationId } },
      }),
    );
    refresh();
    return { ok: true, participation: data };
  } catch (error) {
    return toActionFailure(error);
  }
}
