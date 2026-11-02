import type { components } from './schema';

type Schemas = components['schemas'];

export type Money = Schemas['Money'];
export type User = Schemas['User'];
export type CustomerSession = Schemas['CustomerSession'];
export type OtpChallenge = Schemas['OtpChallenge'];
export type Address = Schemas['Address'];
export type AddressInput = Schemas['AddressInput'];
export type Category = Schemas['Category'];
export type CampaignImage = Schemas['Image'];
export type Variant = Schemas['Variant'];
export type PriceTier = Schemas['PriceTier'];
export type CampaignStatus = Schemas['CustomerCampaignStatus'];
export type CampaignProgress = Schemas['CampaignProgress'];
export type CampaignSummary = Schemas['CampaignSummary'];
export type CampaignDetail = Schemas['CampaignDetail'];
export type JoinRequest = Schemas['JoinRequest'];
export type Participation = Schemas['Participation'];
export type ParticipationStatus = Schemas['ParticipationStatus'];
export type Payment = Schemas['Payment'];
export type PaymentStatus = Schemas['PaymentStatus'];
export type PaymentRejectionReason = Schemas['PaymentRejectionReason'];
export type MoyasarCheckout = Schemas['MoyasarCheckout'];
export type Order = Schemas['Order'];
export type OrderStatus = Schemas['OrderStatus'];
export type Shipment = Schemas['Shipment'];
export type ShipmentStatus = Schemas['ShipmentStatus'];
export type InvoiceRef = Schemas['InvoiceRef'];
export type CursorMeta = Schemas['CursorMeta'];

export type Page<T> = { data: T[]; meta: CursorMeta };
export type CampaignFilter = 'open' | 'upcoming' | 'ended';
