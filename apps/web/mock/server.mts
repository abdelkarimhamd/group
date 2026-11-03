// A stateful fixture API for developing the website without Laravel.
//
//   node mock/server.mts            # listens on 127.0.0.1:4011
//   npm run mock                   # this, behind Prism on :4010 checking
//                                  # every request and response against
//                                  # apps/api/openapi/v1.yaml
//
// It implements the customer endpoints of the contract with in-memory data,
// including the API's fake payment mode (fake_authorized_<payment_id> and
// fake_declined_<payment_id>). The sign-in code is always 123456.
// Set MOCK_SIMULATE=0 to stop the background joins that move progress.

import { createServer, type IncomingMessage, type ServerResponse } from "node:http";
import { randomUUID } from "node:crypto";
import type { components } from "../src/lib/api/schema";
import { categories, productSvg, seedCampaigns, type FixtureCampaign, type Text } from "./fixtures.mts";

type S = components["schemas"];
type Locale = "ar" | "en";

const PORT = Number(process.env.MOCK_PORT ?? 4011);
const HOST = process.env.MOCK_HOST ?? "127.0.0.1";
const PUBLIC_URL = process.env.MOCK_PUBLIC_URL ?? `http://${HOST}:${PORT}`;
const WEB_URL = process.env.MOCK_WEB_URL ?? "http://localhost:3000";
const SIMULATE = process.env.MOCK_SIMULATE !== "0";
const OTP_CODE = "123456";
const POLICY_VERSION = "2026-11";
const RESERVATION_MS = 15 * 60_000;
const HOLD_MS = 14 * 24 * 3600_000;

const startedAt = Date.now();
const campaigns = seedCampaigns(startedAt);

type Customer = {
  id: string;
  phone: string;
  name: string | null;
  email: string | null;
  locale: Locale;
  privacy_consent: { policy_version: string; accepted_at: string } | null;
  created_at: string;
};
type Challenge = { id: string; phone: string; expiresAt: number; attempts: number; used: boolean };
type StoredAddress = S["Address"] & { userId: string };
type StoredPayment = {
  id: string;
  participationId: string;
  status: S["PaymentStatus"];
  amount: number;
  captured: number;
  refunded: number;
  scheme: S["Payment"]["scheme"];
  last4: string | null;
  createdAt: number;
  rejection: S["PaymentRejectionReason"] | null;
};
type StoredParticipation = {
  id: string;
  userId: string;
  campaignId: string;
  variantId: string;
  quantity: number;
  status: S["ParticipationStatus"];
  holdUnit: number;
  finalUnit: number | null;
  captureAmount: number | null;
  reservationExpiresAt: number;
  paymentIds: string[];
  orderId: string | null;
  clientRequestId: string;
  addressId: string;
  createdAt: number;
};
type StoredOrder = {
  id: string;
  number: string;
  userId: string;
  participationId: string;
  status: S["OrderStatus"];
  address: S["AddressInput"];
  createdAt: number;
  deliveredAt: number | null;
};

const customers = new Map<string, Customer>();
const customersByPhone = new Map<string, string>();
const tokens = new Map<string, string>();
const challenges = new Map<string, Challenge>();
const addresses = new Map<string, StoredAddress>();
const participations = new Map<string, StoredParticipation>();
const payments = new Map<string, StoredPayment>();
const orders = new Map<string, StoredOrder>();
let orderSeq = 120;

// ------------------------------------------------------------------ helpers

const iso = (ms: number) => new Date(ms).toISOString().replace(/\.\d{3}Z$/, "Z");

function money(amount: number): S["Money"] {
  const minor = amount % 100;
  return {
    amount_minor: amount,
    currency: "SAR",
    formatted: `${(amount - minor) / 100}.${String(minor).padStart(2, "0")} SAR`,
  };
}

const pick = (text: Text, locale: Locale) => text[locale];

const messages: Record<string, Text> = {
  unauthenticated: { ar: "يرجى تسجيل الدخول.", en: "Please sign in." },
  not_found: { ar: "غير موجود.", en: "Not found." },
  validation: { ar: "البيانات غير صحيحة.", en: "The data is invalid." },
  otp_invalid: { ar: "الرمز غير صحيح.", en: "The code is wrong." },
  otp_expired: { ar: "انتهت صلاحية الرمز.", en: "The code expired." },
  otp_attempts_exhausted: { ar: "محاولات كثيرة.", en: "Too many attempts." },
  privacy_consent_required: { ar: "وافق على سياسة الخصوصية أولًا.", en: "Accept the privacy policy first." },
  price_changed: { ar: "تغيّر السعر.", en: "The price changed." },
  sold_out: { ar: "لا تكفي الوحدات المتبقية.", en: "Not enough units left." },
  campaign_not_joinable: { ar: "الحملة غير متاحة للانضمام.", en: "The campaign is not open for joins." },
  per_customer_limit_exceeded: { ar: "تجاوزت الحد المسموح.", en: "Per-customer limit exceeded." },
  variant_not_in_campaign: { ar: "الخيار غير متاح.", en: "That option is not in this campaign." },
  address_not_found: { ar: "العنوان غير موجود.", en: "Address not found." },
  cancellation_not_allowed: { ar: "لا يمكن الإلغاء الآن.", en: "This join can no longer be cancelled." },
  reservation_expired: { ar: "انتهى الحجز.", en: "The reservation expired." },
  payment_in_progress: { ar: "الدفع قيد المعالجة.", en: "A payment is still in progress." },
  provider_payment_mismatch: { ar: "عملية الدفع لا تطابق.", en: "That payment does not match." },
};

class HttpError extends Error {
  status: number;
  code: string;
  extra: Record<string, unknown>;
  constructor(status: number, code: string, extra: Record<string, unknown> = {}) {
    super(code);
    this.status = status;
    this.code = code;
    this.extra = extra;
  }
}

function priceForQty(campaign: FixtureCampaign, qty: number): number {
  const tier =
    campaign.tiers.find((t) => qty >= t.min_qty && qty <= t.max_qty) ??
    (qty > campaign.max_qty ? campaign.tiers.at(-1) : campaign.tiers[0]);
  return tier!.unit_price_halalas;
}

function reservedQty(campaignId: string, now = Date.now()): number {
  let total = 0;
  for (const p of participations.values()) {
    if (p.campaignId === campaignId && p.status === "pending_payment" && p.reservationExpiresAt > now) {
      total += p.quantity;
    }
  }
  return total;
}

function expireReservations(now = Date.now()) {
  for (const p of participations.values()) {
    if (p.status === "pending_payment" && p.reservationExpiresAt <= now) {
      p.status = "reservation_expired";
      for (const id of p.paymentIds) {
        const payment = payments.get(id)!;
        if (payment.status === "pending_authorization") {
          payment.status = "voided";
          payment.rejection = "reservation_expired";
        }
      }
    }
  }
}

function progressOf(campaign: FixtureCampaign): S["CampaignProgress"] {
  const reserved = reservedQty(campaign.id);
  const confirmed = campaign.deal_confirmed_at !== null;
  return {
    campaign_id: campaign.id,
    status: campaign.status,
    committed_qty: campaign.committed_qty,
    min_target_qty: campaign.min_target_qty,
    max_qty: campaign.max_qty,
    remaining_qty: Math.max(0, campaign.max_qty - campaign.committed_qty - reserved),
    units_to_target: confirmed ? 0 : Math.max(0, campaign.min_target_qty - campaign.committed_qty),
    deal_confirmed_at: campaign.deal_confirmed_at ? iso(campaign.deal_confirmed_at) : null,
    current_unit_price: money(priceForQty(campaign, campaign.committed_qty + 1)),
    deadline_at: iso(campaign.deadline_at),
    updated_at: iso(campaign.updated_at),
    version: campaign.version,
  };
}

function imageOf(campaign: FixtureCampaign, index: number, locale: Locale): S["Image"] {
  return {
    url: `${PUBLIC_URL}/images/${campaign.slug}-${index}.svg`,
    alt: pick(campaign.title, locale),
    product_variant_id: null,
  };
}

function summaryOf(campaign: FixtureCampaign, locale: Locale): S["CampaignSummary"] {
  return {
    id: campaign.id,
    code: campaign.code,
    title: pick(campaign.title, locale),
    image: imageOf(campaign, 0, locale),
    status: campaign.status,
    starts_at: iso(campaign.starts_at),
    deadline_at: iso(campaign.deadline_at),
    current_unit_price: money(priceForQty(campaign, campaign.committed_qty + 1)),
    lowest_unit_price: money(campaign.tiers.at(-1)!.unit_price_halalas),
    progress: progressOf(campaign),
  };
}

function categoryOf(id: string, locale: Locale): S["Category"] {
  const category = categories.find((c) => c.id === id)!;
  return { id: category.id, slug: category.slug, name: pick(category.name, locale), parent_id: category.parent_id };
}

function detailOf(campaign: FixtureCampaign, locale: Locale): S["CampaignDetail"] {
  const current = campaign.committed_qty + 1;
  return {
    ...summaryOf(campaign, locale),
    description: pick(campaign.description, locale),
    category: categoryOf(campaign.category_id, locale),
    variants: campaign.variants.map((v) => ({ id: v.id, sku: v.sku, name: pick(v.name, locale), option_values: v.option_values })),
    images: [0, 1, 2].map((i) => imageOf(campaign, i, locale)),
    tiers: campaign.tiers.map((t) => ({
      min_qty: t.min_qty,
      max_qty: t.max_qty,
      unit_price: money(t.unit_price_halalas),
      is_current: current >= t.min_qty && current <= t.max_qty,
    })),
    per_customer_max_qty: campaign.per_customer_max_qty,
    original_deadline_at: campaign.original_deadline_at ? iso(campaign.original_deadline_at) : null,
    final_unit_price: campaign.final_unit_price_halalas ? money(campaign.final_unit_price_halalas) : null,
    cancellation_rule: "before_deal_confirmed",
  };
}

function userOf(customer: Customer): S["User"] {
  return { ...customer, current_privacy_policy_version: POLICY_VERSION };
}

function paymentOf(payment: StoredPayment, participation: StoredParticipation): S["Payment"] {
  const campaign = campaigns.find((c) => c.id === participation.campaignId)!;
  return {
    id: payment.id,
    status: payment.status,
    amount: money(payment.amount),
    captured_amount: money(payment.captured),
    refunded_amount: money(payment.refunded),
    scheme: payment.scheme,
    card_last4: payment.last4,
    expires_at: iso(payment.createdAt + HOLD_MS),
    rejection_reason: payment.rejection,
    checkout:
      payment.status === "pending_authorization"
        ? {
            publishable_api_key: "pk_test_fake_local",
            amount: payment.amount,
            currency: "SAR",
            description: `${campaign.code} x${participation.quantity}`,
            callback_url: `${WEB_URL}/payments/return?payment_id=${payment.id}`,
            methods: ["creditcard", "applepay"],
            supported_networks: ["mada", "visa", "mastercard"],
            manual: true,
            metadata: { payment_id: payment.id },
          }
        : null,
  };
}

function participationOf(p: StoredParticipation, locale: Locale): S["Participation"] {
  const campaign = campaigns.find((c) => c.id === p.campaignId)!;
  const variant = campaign.variants.find((v) => v.id === p.variantId)!;
  const latest = p.paymentIds.length ? payments.get(p.paymentIds.at(-1)!)! : null;
  return {
    id: p.id,
    campaign: summaryOf(campaign, locale),
    product_variant: { id: variant.id, sku: variant.sku, name: pick(variant.name, locale), option_values: variant.option_values },
    quantity: p.quantity,
    status: p.status,
    hold_unit_price: money(p.holdUnit),
    hold_amount: money(p.holdUnit * p.quantity),
    final_unit_price: p.finalUnit ? money(p.finalUnit) : null,
    capture_amount: p.captureAmount !== null ? money(p.captureAmount) : null,
    reservation_expires_at: iso(p.reservationExpiresAt),
    can_cancel: p.status === "authorized" && campaign.status === "active" && campaign.deal_confirmed_at === null,
    payment: latest ? paymentOf(latest, p) : null,
    order_id: p.orderId,
    created_at: iso(p.createdAt),
  };
}

function orderOf(order: StoredOrder, locale: Locale): S["Order"] {
  const p = participations.get(order.participationId)!;
  const campaign = campaigns.find((c) => c.id === p.campaignId)!;
  const variant = campaign.variants.find((v) => v.id === p.variantId)!;
  const payment = payments.get(p.paymentIds.at(-1)!)!;
  const total = p.captureAmount ?? 0;
  const net = Math.round((total * 100) / 115);
  const delivered = order.deliveredAt;
  const events: S["Shipment"]["events"] = delivered
    ? [
        { status: "picked_up", description: pick({ ar: "استُلمت من المستودع", en: "Collected from the warehouse" }, locale), location: pick({ ar: "الرياض", en: "Riyadh" }, locale), occurred_at: iso(delivered - 3 * 86400_000) },
        { status: "in_transit", description: null, location: pick({ ar: "الرياض", en: "Riyadh" }, locale), occurred_at: iso(delivered - 2 * 86400_000) },
        { status: "out_for_delivery", description: null, location: pick({ ar: "جدة", en: "Jeddah" }, locale), occurred_at: iso(delivered - 6 * 3600_000) },
        { status: "delivered", description: pick({ ar: "سُلّمت للعميل", en: "Handed to the customer" }, locale), location: pick({ ar: "جدة", en: "Jeddah" }, locale), occurred_at: iso(delivered) },
      ]
    : [];
  return {
    id: order.id,
    order_number: order.number,
    campaign: summaryOf(campaign, locale),
    product_variant: { id: variant.id, sku: variant.sku, name: pick(variant.name, locale), option_values: variant.option_values },
    quantity: p.quantity,
    unit_price: money(p.finalUnit ?? p.holdUnit),
    goods_total: money(total),
    delivery_fee: money(0),
    total: money(total),
    vat: money(total - net),
    status: order.status,
    shipping_address: order.address,
    payment: { status: payment.status, captured_amount: money(payment.captured), refunded_amount: money(payment.refunded) },
    shipment: delivered
      ? {
          id: randomUUIDFor(order.id, "shipment"),
          courier: "SMSA",
          tracking_number: "290012345678",
          tracking_url: "https://www.smsaexpress.com/trackingdetails?tracknumbers=290012345678",
          status: "delivered",
          shipped_at: iso(delivered - 3 * 86400_000),
          delivered_at: iso(delivered),
          events,
        }
      : null,
    invoices: [
      {
        id: randomUUIDFor(order.id, "invoice"),
        type: "simplified_tax_invoice",
        invoice_number: `INV-2026-000${order.number.slice(-3)}`,
        issued_at: iso(order.createdAt),
        total: money(total),
        pdf_url: null,
      },
    ],
    delivered_at: delivered ? iso(delivered) : null,
    return_window_ends_at: delivered ? iso(delivered + 7 * 86400_000) : null,
    created_at: iso(order.createdAt),
  };
}

const derived = new Map<string, string>();
function randomUUIDFor(id: string, kind: string): string {
  const key = `${kind}:${id}`;
  if (!derived.has(key)) derived.set(key, randomUUID());
  return derived.get(key)!;
}

function page<T>(items: T[], url: URL) {
  const perPage = Math.min(100, Math.max(1, Number(url.searchParams.get("per_page") ?? 20) || 20));
  const offset = Math.max(0, Number(url.searchParams.get("cursor") ?? 0) || 0);
  const slice = items.slice(offset, offset + perPage);
  const next = offset + perPage < items.length ? String(offset + perPage) : null;
  return { data: slice, meta: { next_cursor: next, per_page: perPage } };
}

/** New customers get a finished order and a released hold, so My orders has something to show. */
function seedHistory(customer: Customer, locale: Locale) {
  const now = Date.now();
  const address: StoredAddress = {
    id: randomUUID(),
    userId: customer.id,
    recipient_name: locale === "ar" ? "نورة العتيبي" : "Noura Alotaibi",
    recipient_phone: customer.phone,
    city: locale === "ar" ? "جدة" : "Jeddah",
    district: locale === "ar" ? "الروضة" : "Al Rawdah",
    street: locale === "ar" ? "شارع الأمير سلطان" : "Prince Sultan Road",
    building_no: "2401",
    postal_code: "23435",
    additional_no: "3344",
    short_address: "JRDA2401",
    lat: null,
    lng: null,
    is_default: true,
  };
  addresses.set(address.id, address);

  const lamp = campaigns.find((c) => c.slug === "lamp")!;
  const won: StoredParticipation = {
    id: randomUUID(), userId: customer.id, campaignId: lamp.id, variantId: lamp.variants[0].id, quantity: 2,
    status: "captured", holdUnit: 7900, finalUnit: 5900, captureAmount: 11800,
    reservationExpiresAt: lamp.starts_at + DAY_MS + RESERVATION_MS, paymentIds: [], orderId: null,
    clientRequestId: randomUUID(), addressId: address.id, createdAt: lamp.starts_at + DAY_MS,
  };
  const wonPayment: StoredPayment = {
    id: randomUUID(), participationId: won.id, status: "captured", amount: 15800, captured: 11800, refunded: 0,
    scheme: "mada", last4: "4242", createdAt: won.createdAt, rejection: null,
  };
  won.paymentIds.push(wonPayment.id);
  const order: StoredOrder = {
    id: randomUUID(), number: `ORD-2610-000${orderSeq++}`, userId: customer.id, participationId: won.id,
    status: "delivered", address: stripAddress(address), createdAt: lamp.deadline_at + 3600_000, deliveredAt: now - 2 * DAY_MS,
  };
  won.orderId = order.id;
  participations.set(won.id, won);
  payments.set(wonPayment.id, wonPayment);
  orders.set(order.id, order);

  const fan = campaigns.find((c) => c.slug === "fan")!;
  const lost: StoredParticipation = {
    id: randomUUID(), userId: customer.id, campaignId: fan.id, variantId: fan.variants[0].id, quantity: 1,
    status: "released", holdUnit: 5900, finalUnit: null, captureAmount: null,
    reservationExpiresAt: fan.starts_at + DAY_MS + RESERVATION_MS, paymentIds: [], orderId: null,
    clientRequestId: randomUUID(), addressId: address.id, createdAt: fan.starts_at + DAY_MS,
  };
  const lostPayment: StoredPayment = {
    id: randomUUID(), participationId: lost.id, status: "voided", amount: 5900, captured: 0, refunded: 0,
    scheme: "visa", last4: "1111", createdAt: lost.createdAt, rejection: "campaign_closed",
  };
  lost.paymentIds.push(lostPayment.id);
  participations.set(lost.id, lost);
  payments.set(lostPayment.id, lostPayment);
}

const DAY_MS = 86400_000;

function stripAddress(address: StoredAddress): S["AddressInput"] {
  const { id: _id, userId: _user, ...rest } = address;
  return rest;
}

// ------------------------------------------------------------------ routing

type Ctx = {
  req: IncomingMessage;
  url: URL;
  locale: Locale;
  body: Record<string, unknown>;
  params: Record<string, string>;
};

function requireUser(ctx: Ctx): Customer {
  const header = ctx.req.headers.authorization ?? "";
  const token = header.startsWith("Bearer ") ? header.slice(7) : "";
  const userId = tokens.get(token);
  const customer = userId ? customers.get(userId) : undefined;
  if (!customer) throw new HttpError(401, "unauthenticated");
  return customer;
}

function validation(errors: Record<string, string>): never {
  throw new HttpError(422, "validation_failed", {
    errors: Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, [value]])),
  });
}

function findCampaign(id: string): FixtureCampaign {
  const campaign = campaigns.find((c) => c.id === id && c.status !== undefined);
  if (!campaign) throw new HttpError(404, "not_found");
  return campaign;
}

function ownParticipation(ctx: Ctx, user: Customer): StoredParticipation {
  const p = participations.get(ctx.params.participation_id);
  if (!p || p.userId !== user.id) throw new HttpError(404, "not_found");
  return p;
}

function newPayment(p: StoredParticipation): StoredPayment {
  const payment: StoredPayment = {
    id: randomUUID(), participationId: p.id, status: "pending_authorization", amount: p.holdUnit * p.quantity,
    captured: 0, refunded: 0, scheme: null, last4: null, createdAt: Date.now(), rejection: null,
  };
  payments.set(payment.id, payment);
  p.paymentIds.push(payment.id);
  return payment;
}

const ADDRESS_FIELDS = ["recipient_name", "recipient_phone", "city", "district", "street", "building_no", "postal_code"] as const;

function readAddress(body: Record<string, unknown>): S["AddressInput"] {
  const errors: Record<string, string> = {};
  for (const field of ADDRESS_FIELDS) {
    if (typeof body[field] !== "string" || !(body[field] as string).trim()) errors[field] = "required";
  }
  if (Object.keys(errors).length) validation(errors);
  return {
    recipient_name: String(body.recipient_name),
    recipient_phone: String(body.recipient_phone),
    city: String(body.city),
    district: String(body.district),
    street: String(body.street),
    building_no: String(body.building_no),
    postal_code: String(body.postal_code),
    additional_no: (body.additional_no as string | null | undefined) ?? null,
    short_address: (body.short_address as string | null | undefined) ?? null,
    lat: (body.lat as number | null | undefined) ?? null,
    lng: (body.lng as number | null | undefined) ?? null,
    is_default: Boolean(body.is_default),
  };
}

function setDefaultAddress(userId: string, id: string) {
  for (const address of addresses.values()) {
    if (address.userId === userId) address.is_default = address.id === id;
  }
}

function userAddresses(userId: string) {
  return [...addresses.values()]
    .filter((a) => a.userId === userId)
    .sort((a, b) => Number(b.is_default) - Number(a.is_default))
    .map(({ userId: _user, ...rest }) => rest);
}

type Handler = (ctx: Ctx) => { status?: number; body?: unknown; headers?: Record<string, string> } | void;

const routes: Array<[string, RegExp, Handler]> = [
  ["GET", /^\/health$/, () => ({ body: { status: "ok", message: "ok", checks: { database: "ok", redis: "ok" }, time: iso(Date.now()) } })],

  ["POST", /^\/auth\/otp$/, (ctx) => {
    const phone = String(ctx.body.phone ?? "");
    if (!/^\+9665\d{8}$/.test(phone)) validation({ phone: "invalid" });
    const challenge: Challenge = { id: randomUUID(), phone, expiresAt: Date.now() + 5 * 60_000, attempts: 0, used: false };
    challenges.set(challenge.id, challenge);
    console.log(`[mock] sign-in code for ${phone}: ${OTP_CODE}`);
    return {
      status: 202,
      body: { data: { challenge_id: challenge.id, expires_at: iso(challenge.expiresAt), resend_available_at: iso(Date.now() + 30_000) } },
    };
  }],

  ["POST", /^\/auth\/otp\/verify$/, (ctx) => {
    const challenge = challenges.get(String(ctx.body.challenge_id ?? ""));
    if (!challenge || challenge.used) throw new HttpError(422, "otp_expired");
    if (challenge.expiresAt < Date.now()) throw new HttpError(422, "otp_expired");
    if (challenge.attempts >= 5) throw new HttpError(422, "otp_attempts_exhausted");
    if (ctx.body.code !== OTP_CODE) {
      challenge.attempts += 1;
      throw new HttpError(422, challenge.attempts >= 5 ? "otp_attempts_exhausted" : "otp_invalid");
    }
    challenge.used = true;
    let isNew = false;
    let userId = customersByPhone.get(challenge.phone);
    if (!userId) {
      isNew = true;
      const customer: Customer = {
        id: randomUUID(), phone: challenge.phone, name: null, email: null, locale: ctx.locale,
        privacy_consent: null, created_at: iso(Date.now()),
      };
      customers.set(customer.id, customer);
      customersByPhone.set(customer.phone, customer.id);
      userId = customer.id;
      seedHistory(customer, ctx.locale);
    }
    const token = `${tokens.size + 1}|${randomUUID().replaceAll("-", "")}`;
    tokens.set(token, userId);
    return { body: { data: { token, token_type: "Bearer", user: userOf(customers.get(userId)!), is_new_user: isNew } } };
  }],

  ["POST", /^\/auth\/logout$/, (ctx) => {
    requireUser(ctx);
    tokens.delete((ctx.req.headers.authorization ?? "").slice(7));
    return { status: 204 };
  }],

  ["GET", /^\/me$/, (ctx) => ({ body: { data: userOf(requireUser(ctx)) } })],

  ["PATCH", /^\/me$/, (ctx) => {
    const user = requireUser(ctx);
    if ("name" in ctx.body) user.name = (ctx.body.name as string | null) || null;
    if ("email" in ctx.body) {
      const email = (ctx.body.email as string | null) || null;
      // The API uses a strict check: ASCII local part and a dotted domain.
      if (email !== null && !/^[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/.test(email)) {
        validation({ email: "invalid" });
      }
      user.email = email;
    }
    if (ctx.body.locale === "ar" || ctx.body.locale === "en") user.locale = ctx.body.locale;
    return { body: { data: userOf(user) } };
  }],

  ["POST", /^\/me\/privacy-consent$/, (ctx) => {
    const user = requireUser(ctx);
    if (ctx.body.policy_version !== POLICY_VERSION) validation({ policy_version: "outdated" });
    user.privacy_consent = { policy_version: POLICY_VERSION, accepted_at: iso(Date.now()) };
    return { body: { data: userOf(user) } };
  }],

  ["GET", /^\/me\/addresses$/, (ctx) => ({ body: { data: userAddresses(requireUser(ctx).id) } })],

  ["POST", /^\/me\/addresses$/, (ctx) => {
    const user = requireUser(ctx);
    const input = readAddress(ctx.body);
    const first = userAddresses(user.id).length === 0;
    const address: StoredAddress = { id: randomUUID(), userId: user.id, ...input, is_default: input.is_default || first };
    addresses.set(address.id, address);
    if (address.is_default) setDefaultAddress(user.id, address.id);
    const { userId: _user, ...data } = address;
    return { status: 201, body: { data } };
  }],

  ["PATCH", /^\/me\/addresses\/(?<address_id>[^/]+)$/, (ctx) => {
    const user = requireUser(ctx);
    const address = addresses.get(ctx.params.address_id);
    if (!address || address.userId !== user.id) throw new HttpError(404, "not_found");
    // Like the API: PATCH replaces the whole address, and is_default: false
    // never unsets the default (it only moves when another address takes it).
    const wasDefault = address.is_default;
    Object.assign(address, readAddress(ctx.body));
    address.is_default = address.is_default || wasDefault;
    if (address.is_default) setDefaultAddress(user.id, address.id);
    const { userId: _user, ...data } = address;
    return { body: { data } };
  }],

  ["DELETE", /^\/me\/addresses\/(?<address_id>[^/]+)$/, (ctx) => {
    const user = requireUser(ctx);
    const address = addresses.get(ctx.params.address_id);
    if (!address || address.userId !== user.id) throw new HttpError(404, "not_found");
    addresses.delete(address.id);
    return { status: 204 };
  }],

  ["GET", /^\/categories$/, (ctx) => ({ body: { data: categories.map((c) => categoryOf(c.id, ctx.locale)) } })],

  ["GET", /^\/campaigns$/, (ctx) => {
    expireReservations();
    const filter = ctx.url.searchParams.get("filter") ?? "open";
    const category = ctx.url.searchParams.get("category");
    const categoryId = category ? categories.find((c) => c.slug === category)?.id ?? "none" : null;
    let list = campaigns.filter((c) => (categoryId ? c.category_id === categoryId : true));
    if (filter === "upcoming") list = list.filter((c) => c.status === "scheduled").sort((a, b) => a.starts_at - b.starts_at);
    else if (filter === "ended") list = list.filter((c) => !["scheduled", "active"].includes(c.status)).sort((a, b) => b.deadline_at - a.deadline_at);
    else list = list.filter((c) => c.status === "active").sort((a, b) => a.deadline_at - b.deadline_at);
    return { body: page(list.map((c) => summaryOf(c, ctx.locale)), ctx.url) };
  }],

  ["GET", /^\/campaigns\/(?<campaign_id>[^/]+)$/, (ctx) => {
    expireReservations();
    return { body: { data: detailOf(findCampaign(ctx.params.campaign_id), ctx.locale) } };
  }],

  ["GET", /^\/campaigns\/(?<campaign_id>[^/]+)\/progress$/, (ctx) => {
    expireReservations();
    return { body: { data: progressOf(findCampaign(ctx.params.campaign_id)) } };
  }],

  ["POST", /^\/campaigns\/(?<campaign_id>[^/]+)\/participations$/, (ctx) => {
    const user = requireUser(ctx);
    const campaign = findCampaign(ctx.params.campaign_id);
    expireReservations();
    const clientRequestId = String(ctx.body.client_request_id ?? "");
    const earlier = [...participations.values()].find((p) => p.userId === user.id && p.clientRequestId === clientRequestId);
    if (earlier) return { status: 201, body: { data: participationOf(earlier, ctx.locale) } };

    if (!user.privacy_consent || user.privacy_consent.policy_version !== POLICY_VERSION) {
      throw new HttpError(403, "privacy_consent_required");
    }
    if (campaign.status !== "active" || campaign.deadline_at <= Date.now()) throw new HttpError(409, "campaign_not_joinable");
    const quantity = Number(ctx.body.quantity);
    if (!Number.isInteger(quantity) || quantity < 1) validation({ quantity: "invalid" });
    if (!campaign.variants.some((v) => v.id === ctx.body.product_variant_id)) throw new HttpError(422, "variant_not_in_campaign");
    const address = addresses.get(String(ctx.body.delivery_address_id ?? ""));
    if (!address || address.userId !== user.id) throw new HttpError(422, "address_not_found");
    if (campaign.per_customer_max_qty) {
      const held = [...participations.values()]
        .filter((p) => p.userId === user.id && p.campaignId === campaign.id && ["pending_payment", "authorized"].includes(p.status))
        .reduce((sum, p) => sum + p.quantity, 0);
      if (held + quantity > campaign.per_customer_max_qty) throw new HttpError(422, "per_customer_limit_exceeded");
    }
    const remaining = campaign.max_qty - campaign.committed_qty - reservedQty(campaign.id);
    if (quantity > remaining) throw new HttpError(409, "sold_out", { remaining_qty: Math.max(0, remaining) });
    const unit = priceForQty(campaign, campaign.committed_qty + 1);
    if (Number(ctx.body.shown_unit_price_halalas) !== unit) {
      throw new HttpError(409, "price_changed", { current_unit_price: money(unit) });
    }
    const participation: StoredParticipation = {
      id: randomUUID(), userId: user.id, campaignId: campaign.id, variantId: String(ctx.body.product_variant_id),
      quantity, status: "pending_payment", holdUnit: unit, finalUnit: null, captureAmount: null,
      reservationExpiresAt: Date.now() + RESERVATION_MS, paymentIds: [], orderId: null,
      clientRequestId, addressId: address.id, createdAt: Date.now(),
    };
    participations.set(participation.id, participation);
    newPayment(participation);
    campaign.updated_at = Date.now();
    campaign.version += 1;
    return { status: 201, body: { data: participationOf(participation, ctx.locale) } };
  }],

  ["GET", /^\/me\/participations$/, (ctx) => {
    const user = requireUser(ctx);
    expireReservations();
    const status = ctx.url.searchParams.get("status");
    const list = [...participations.values()]
      .filter((p) => p.userId === user.id && (!status || p.status === status))
      .sort((a, b) => b.createdAt - a.createdAt)
      .map((p) => participationOf(p, ctx.locale));
    return { body: page(list, ctx.url) };
  }],

  ["GET", /^\/me\/participations\/(?<participation_id>[^/]+)$/, (ctx) => {
    const user = requireUser(ctx);
    expireReservations();
    return { body: { data: participationOf(ownParticipation(ctx, user), ctx.locale) } };
  }],

  ["POST", /^\/me\/participations\/(?<participation_id>[^/]+)\/cancel$/, (ctx) => {
    const user = requireUser(ctx);
    const p = ownParticipation(ctx, user);
    if (!participationOf(p, ctx.locale).can_cancel) throw new HttpError(409, "cancellation_not_allowed");
    const campaign = findCampaign(p.campaignId);
    p.status = "cancelled";
    campaign.committed_qty -= p.quantity;
    campaign.updated_at = Date.now();
    campaign.version += 1;
    const payment = payments.get(p.paymentIds.at(-1)!)!;
    payment.status = "voided";
    payment.rejection = "participation_cancelled";
    return { body: { data: participationOf(p, ctx.locale) } };
  }],

  ["POST", /^\/me\/participations\/(?<participation_id>[^/]+)\/payments$/, (ctx) => {
    const user = requireUser(ctx);
    expireReservations();
    const p = ownParticipation(ctx, user);
    if (p.status !== "pending_payment") throw new HttpError(409, "reservation_expired");
    const latest = payments.get(p.paymentIds.at(-1)!)!;
    if (latest.status !== "failed") throw new HttpError(409, "payment_in_progress");
    return { status: 201, body: { data: paymentOf(newPayment(p), p) } };
  }],

  ["POST", /^\/payments\/(?<payment_id>[^/]+)\/sync$/, (ctx) => {
    const user = requireUser(ctx);
    const payment = payments.get(ctx.params.payment_id);
    const p = payment ? participations.get(payment.participationId) : undefined;
    if (!payment || !p || p.userId !== user.id) throw new HttpError(404, "not_found");
    const providerId = String(ctx.body.provider_payment_id ?? "");
    const match = /^fake_(authorized|declined)_(.+)$/.exec(providerId);
    if (!match || match[2] !== payment.id) throw new HttpError(422, "provider_payment_mismatch");
    expireReservations();
    if (payment.status === "pending_authorization") {
      if (p.status !== "pending_payment") {
        payment.status = "voided";
        payment.rejection = p.status === "reservation_expired" ? "reservation_expired" : "participation_cancelled";
      } else if (match[1] === "authorized") {
        payment.status = "authorized";
        payment.scheme = "mada";
        payment.last4 = "0000";
        p.status = "authorized";
        const campaign = findCampaign(p.campaignId);
        campaign.committed_qty += p.quantity;
        if (!campaign.deal_confirmed_at && campaign.committed_qty >= campaign.min_target_qty) campaign.deal_confirmed_at = Date.now();
        campaign.updated_at = Date.now();
        campaign.version += 1;
      } else {
        payment.status = "failed";
        payment.rejection = "declined";
      }
    }
    return { body: { data: participationOf(p, ctx.locale) } };
  }],

  ["GET", /^\/me\/orders$/, (ctx) => {
    const user = requireUser(ctx);
    const list = [...orders.values()]
      .filter((o) => o.userId === user.id)
      .sort((a, b) => b.createdAt - a.createdAt)
      .map((o) => orderOf(o, ctx.locale));
    return { body: page(list, ctx.url) };
  }],

  ["GET", /^\/me\/orders\/(?<order_id>[^/]+)$/, (ctx) => {
    const user = requireUser(ctx);
    const order = orders.get(ctx.params.order_id);
    if (!order || order.userId !== user.id) throw new HttpError(404, "not_found");
    return { body: { data: orderOf(order, ctx.locale) } };
  }],
];

// ------------------------------------------------------------------ server

function send(res: ServerResponse, status: number, body: unknown, locale: Locale, headers: Record<string, string> = {}) {
  res.writeHead(status, {
    "Content-Language": locale,
    "X-Request-Id": randomUUID(),
    ...(body === undefined ? {} : { "Content-Type": "application/json" }),
    ...headers,
  });
  res.end(body === undefined ? undefined : JSON.stringify(body));
}

async function readJson(req: IncomingMessage): Promise<Record<string, unknown>> {
  const chunks: Buffer[] = [];
  for await (const chunk of req) chunks.push(chunk as Buffer);
  if (!chunks.length) return {};
  try {
    const value = JSON.parse(Buffer.concat(chunks).toString("utf8"));
    return value && typeof value === "object" ? value : {};
  } catch {
    return {};
  }
}

const server = createServer(async (req, res) => {
  const url = new URL(req.url ?? "/", `http://${req.headers.host ?? "localhost"}`);
  const locale: Locale = (req.headers["accept-language"] ?? "ar").toString().startsWith("en") ? "en" : "ar";
  const path = url.pathname.replace(/^\/api\/v1/, "");

  const image = /^\/images\/([a-z]+)-(\d)\.svg$/.exec(path);
  if (req.method === "GET" && image) {
    const campaign = campaigns.find((c) => c.slug === image[1]);
    if (!campaign) return send(res, 404, { message: "not found" }, locale);
    res.writeHead(200, { "Content-Type": "image/svg+xml", "Cache-Control": "public, max-age=3600" });
    return res.end(productSvg(campaign, Number(image[2])));
  }

  for (const [method, pattern, handler] of routes) {
    const match = pattern.exec(path);
    if (!match || method !== req.method) continue;
    try {
      const body = ["POST", "PATCH", "PUT"].includes(method) ? await readJson(req) : {};
      const result = handler({ req, url, locale, body, params: match.groups ?? {} }) ?? {};
      return send(res, result.status ?? 200, result.body, locale, result.headers);
    } catch (error) {
      if (error instanceof HttpError) {
        const text = messages[error.code] ?? messages.validation;
        return send(res, error.status, { message: pick(text, locale), code: error.code, ...error.extra }, locale);
      }
      console.error(error);
      return send(res, 500, { message: "Internal error", code: "internal" }, locale);
    }
  }
  send(res, 404, { message: pick(messages.not_found, locale), code: "not_found" }, locale);
});

if (SIMULATE) {
  // Other shoppers: every few seconds someone joins an open campaign.
  setInterval(() => {
    const open = campaigns.filter((c) => c.status === "active" && c.committed_qty < c.max_qty - 5);
    const campaign = open[Math.floor(Math.random() * open.length)];
    if (!campaign) return;
    campaign.committed_qty += 1;
    if (!campaign.deal_confirmed_at && campaign.committed_qty >= campaign.min_target_qty) campaign.deal_confirmed_at = Date.now();
    campaign.updated_at = Date.now();
    campaign.version += 1;
  }, 8000);
}

server.listen(PORT, HOST, () => {
  console.log(`[mock] fixture API on ${PUBLIC_URL} (sign-in code ${OTP_CODE}, simulate=${SIMULATE})`);
});
