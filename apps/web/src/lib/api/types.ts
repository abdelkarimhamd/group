import type { components } from "./schema";

// Short names for the contract's schemas (apps/api/openapi/v1.yaml).
type S = components["schemas"];

export type Money = S["Money"];
export type User = S["User"];
export type OtpChallenge = S["OtpChallenge"];
export type CustomerSession = S["CustomerSession"];
export type Address = S["Address"];
export type AddressInput = S["AddressInput"];
export type Category = S["Category"];
export type Image = S["Image"];
export type Variant = S["Variant"];
export type PriceTier = S["PriceTier"];
export type CampaignStatus = S["CustomerCampaignStatus"];
export type CampaignProgress = S["CampaignProgress"];
export type CampaignSummary = S["CampaignSummary"];
export type CampaignDetail = S["CampaignDetail"];
export type JoinRequest = S["JoinRequest"];
export type JoinConflict = S["JoinConflict"];
export type ParticipationStatus = S["ParticipationStatus"];
export type Participation = S["Participation"];
export type PaymentStatus = S["PaymentStatus"];
export type PaymentRejectionReason = S["PaymentRejectionReason"];
export type Payment = S["Payment"];
export type MoyasarCheckout = S["MoyasarCheckout"];
export type OrderStatus = S["OrderStatus"];
export type Order = S["Order"];
export type InvoiceRef = S["InvoiceRef"];
export type ShipmentStatus = S["ShipmentStatus"];
export type Shipment = S["Shipment"];
export type CursorMeta = S["CursorMeta"];
export type ApiErrorBody = S["ValidationError"] & {
  current_unit_price?: Money;
  remaining_qty?: number;
};

export type CampaignFilter = "open" | "upcoming" | "ended";
