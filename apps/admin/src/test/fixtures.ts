import type {
  AdminCampaign,
  AdminRole,
  AdminUser,
  ApprovalRequest,
  MarginReport,
  Money,
  Supplier,
} from '../api/types';
import type { Permission } from '../auth/permissions';
import { formatMinor } from '../lib/money';

export const sar = (amount_minor: number): Money => ({
  amount_minor,
  currency: 'SAR',
  formatted: formatMinor(amount_minor, 'SAR'),
});

export function adminUser(
  overrides: Partial<AdminUser> & { permissions?: Permission[] } = {},
): AdminUser {
  return {
    id: '00000000-0000-4000-8000-000000000001',
    email: 'sara@example.com',
    name: 'Sara Alqahtani',
    phone: '+966512345678',
    status: 'active',
    roles: ['campaign_manager'],
    permissions: [],
    last_login_at: '2026-11-01T09:00:00Z',
    created_at: '2026-10-01T09:00:00Z',
    ...overrides,
  };
}

export function campaign(overrides: Partial<AdminCampaign> = {}): AdminCampaign {
  return {
    id: '11111111-1111-4111-8111-111111111111',
    code: 'GB-2611-0007',
    status: 'draft',
    product: { id: '22222222-2222-4222-8222-222222222222', name_ar: 'سماعة', name_en: 'Headset' },
    supplier: { id: '33333333-3333-4333-8333-333333333333', name: 'Shenzhen Audio', moq: 50 },
    supplier_quotation_id: '44444444-4444-4444-8444-444444444444',
    title_ar: 'سماعة لاسلكية',
    title_en: 'Wireless headset',
    variants: [],
    min_target_qty: 100,
    max_qty: 300,
    committed_qty: 0,
    reserved_qty: 0,
    per_customer_max_qty: 5,
    starts_at: '2026-11-10T09:00:00Z',
    deadline_at: null,
    original_deadline_at: null,
    extended_at: null,
    extension_reason: null,
    deal_confirmed_at: null,
    close_reason: null,
    closing_step: null,
    closed_at: null,
    final_qty: null,
    final_unit_price: null,
    capture_by: null,
    supplier_decision_due_at: null,
    captured_qty: null,
    end_reason: null,
    tier_version: 1,
    tiers: [
      { min_qty: 1, max_qty: 100, unit_price: sar(9900) },
      { min_qty: 101, max_qty: 300, unit_price: sar(8900) },
    ],
    landed_cost_per_unit: sar(6000),
    delivery_mode: 'warehouse',
    created_by: {
      type: 'admin',
      id: '00000000-0000-4000-8000-000000000001',
      name: 'Sara Alqahtani',
    },
    approved_by: null,
    approved_at: null,
    lock_version: 3,
    available_actions: [],
    ...overrides,
  };
}

export function marginReport(passes: boolean): MarginReport {
  const margin_bp = passes ? 2200 : 900;
  return {
    min_margin_bp: 1500,
    passes,
    landed_cost_per_unit: sar(6000),
    payment_fee_estimate: sar(200),
    delivery_cost_estimate: sar(500),
    tiers: [
      {
        min_qty: 1,
        max_qty: 300,
        unit_price: sar(9900),
        net_price: sar(8609),
        margin: sar(1909),
        margin_bp,
        passes,
      },
    ],
    warnings: [],
  };
}

export function approvalRequest(overrides: Partial<ApprovalRequest> = {}): ApprovalRequest {
  return {
    id: '55555555-5555-4555-8555-555555555555',
    type: 'manual_refund',
    subject_type: 'refund',
    subject_id: '66666666-6666-4666-8666-666666666666',
    payload: { amount_minor: 4500 },
    status: 'pending',
    requested_by: { type: 'admin', id: '00000000-0000-4000-8000-000000000009', name: 'Omar' },
    decided_by: null,
    reason: null,
    created_at: '2026-11-02T08:00:00Z',
    decided_at: null,
    ...overrides,
  };
}

export function supplier(overrides: Partial<Supplier> = {}): Supplier {
  return {
    id: '33333333-3333-4333-8333-333333333333',
    name: 'Shenzhen Audio',
    alibaba_store_url: 'https://shenzhen-audio.en.alibaba.com',
    country: 'CN',
    contact_name: 'Lily',
    contact_channel: 'Alibaba chat',
    quality_rating: 4,
    review_notes: null,
    status: 'prospect',
    reviewed_by: null,
    reviewed_at: null,
    ...overrides,
  };
}

export function role(
  name: AdminRole['name'],
  permissions: Permission[],
  grantable: boolean,
): AdminRole {
  return { name, permissions, grantable };
}

/** A cursor page in the API's envelope. */
export function page<T>(data: T[], next_cursor: string | null = null) {
  return { data, meta: { next_cursor, per_page: 25 } };
}
