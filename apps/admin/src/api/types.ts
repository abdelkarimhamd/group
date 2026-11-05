import type { components } from './schema';

/** Shorthands for schemas in apps/api/openapi/v1.yaml. Regenerate with `npm run api:types`. */
type S = components['schemas'];

export type Money = S['Money'];
export type Actor = S['Actor'];
export type CursorMeta = S['CursorMeta'];
export type AdminUser = S['AdminUser'];
export type AdminRoleName = S['AdminRoleName'];
/** A role, the permissions it grants and whether the signed-in admin may grant it. */
export type AdminRole = S['AdminRole'];
export type AdminCreateInput = S['AdminCreateInput'];
export type AdminUpdateInput = S['AdminUpdateInput'];
export type OtpChallenge = S['OtpChallenge'];
export type AdminSession = S['AdminSession'];

export type AdminCategory = S['AdminCategory'];
export type AdminCategoryInput = S['AdminCategoryInput'];
export type AdminProduct = S['AdminProduct'];
export type AdminProductInput = S['AdminProductInput'];
export type AdminVariant = S['AdminVariant'];
export type AdminVariantInput = S['AdminVariantInput'];
export type AdminImage = S['AdminImage'];
export type ProductStatus = NonNullable<AdminProductInput['status']>;

export type Supplier = S['Supplier'];
export type SupplierInput = S['SupplierInput'];
export type SupplierStatus = Supplier['status'];
export type Quotation = S['Quotation'];
export type QuotationInput = S['QuotationInput'];
export type QuotationStatus = Quotation['status'];

export type CampaignStatus = S['CampaignStatus'];
export type ClosingStep = S['ClosingStep'];
export type AdminCampaign = S['AdminCampaign'];
export type AdminCampaignInput = S['AdminCampaignInput'];
export type CampaignAction = AdminCampaign['available_actions'][number];
export type AdminTier = S['AdminTier'];
export type TierSetInput = S['TierSetInput'];
export type MarginReport = S['MarginReport'];
export type MarginWarning = MarginReport['warnings'][number];
export type ExtensionWindow = S['ExtensionWindow'];
export type SupplierDecisionInput = S['SupplierDecisionInput'];
export type CampaignEvent = S['CampaignEvent'];
export type AdminParticipation = S['AdminParticipation'];
export type ParticipationStatus = S['ParticipationStatus'];

export type PaymentStatus = S['PaymentStatus'];
export type AdminPayment = S['AdminPayment'];
export type PaymentTransaction = S['PaymentTransaction'];
export type Refund = S['Refund'];
export type RefundInput = S['RefundInput'];
export type RefundStatus = S['RefundStatus'];
export type RefundReason = S['RefundReason'];
export type ApprovalRequest = S['ApprovalRequest'];
export type ApprovalStatus = ApprovalRequest['status'];

export type OrderStatus = S['OrderStatus'];
export type AdminOrder = S['AdminOrder'];
export type Shipment = S['Shipment'];
export type ShipmentStatus = S['ShipmentStatus'];
export type ShipmentInput = S['ShipmentInput'];
export type ShipmentEventInput = S['ShipmentEventInput'];

export type PurchaseOrder = S['PurchaseOrder'];
export type PurchaseOrderStatus = S['PurchaseOrderStatus'];
export type PurchaseOrderInput = S['PurchaseOrderInput'];
export type PurchaseOrderDocument = S['PurchaseOrderDocument'];
export type PurchaseOrderDocumentKind = S['PurchaseOrderDocumentKind'];
export type InboundReceipt = S['InboundReceipt'];
export type InboundReceiptInput = S['InboundReceiptInput'];

export type ReconciliationRun = S['ReconciliationRun'];
export type ReconciliationItem = S['ReconciliationItem'];
export type AuditLogEntry = S['AuditLogEntry'];
