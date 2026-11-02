/**
 * Realistic API payloads for tests and screenshots, shaped by the contract types. Uses only
 * type imports so Node can also load it with --experimental-strip-types.
 */
import type {
  Address,
  CampaignDetail,
  CampaignProgress,
  CampaignSummary,
  Category,
  Money,
  Order,
  Participation,
  Payment,
  User,
} from '@/api/types';

type Lang = 'ar' | 'en';

export function sar(amountMinor: number): Money {
  const whole = Math.trunc(amountMinor / 100);
  const fraction = String(amountMinor % 100).padStart(2, '0');
  return { amount_minor: amountMinor, currency: 'SAR', formatted: `${whole}.${fraction} SAR` };
}

const HOUR = 3_600_000;
const iso = (ms: number) => new Date(ms).toISOString();

export const ids = {
  kettle: '0b8f0e2c-1c55-4a8b-9a0e-6f4f8d1a0001',
  blender: '0b8f0e2c-1c55-4a8b-9a0e-6f4f8d1a0002',
  lamp: '0b8f0e2c-1c55-4a8b-9a0e-6f4f8d1a0003',
  organizer: '0b8f0e2c-1c55-4a8b-9a0e-6f4f8d1a0004',
  kettleBlack: '5a1f6a30-2c0b-4d55-8f53-9f2a3f000001',
  kettleWhite: '5a1f6a30-2c0b-4d55-8f53-9f2a3f000002',
  user: '7d0c6a39-4a0e-4b8f-9d2b-2f6b7c000001',
  address: '9e3e2a71-6c4b-4f0f-9f6a-1a2b3c000001',
  participation: 'c1a2b3c4-d5e6-4f70-8a9b-0c1d2e000001',
  payment: 'd1a2b3c4-d5e6-4f70-8a9b-0c1d2e000001',
  order: 'e1a2b3c4-d5e6-4f70-8a9b-0c1d2e000001',
  category: 'f1a2b3c4-d5e6-4f70-8a9b-0c1d2e000001',
};

const titles: Record<string, Record<Lang, string>> = {
  kettle: { ar: 'غلاية كهربائية 1.7 لتر', en: 'Electric kettle 1.7 L' },
  blender: { ar: 'خلاط عصائر محمول', en: 'Portable smoothie blender' },
  lamp: { ar: 'مصباح مكتب LED قابل للطي', en: 'Foldable LED desk lamp' },
  organizer: { ar: 'منظم أدراج مطبخ (6 قطع)', en: 'Kitchen drawer organizer (6 pcs)' },
};

export function progress(overrides: Partial<CampaignProgress> = {}): CampaignProgress {
  const base: CampaignProgress = {
    campaign_id: ids.kettle,
    status: 'active',
    committed_qty: 53,
    min_target_qty: 100,
    max_qty: 150,
    remaining_qty: 95,
    units_to_target: 47,
    deal_confirmed_at: null,
    current_unit_price: sar(6900),
    deadline_at: iso(Date.now() + 2 * 24 * HOUR + 5 * HOUR),
    updated_at: iso(Date.now() - 60_000),
  };
  return { ...base, ...overrides };
}

export function campaignSummary(
  key: keyof typeof titles = 'kettle',
  lang: Lang = 'ar',
  overrides: Partial<CampaignSummary> = {},
): CampaignSummary {
  const id = ids[key as keyof typeof ids];
  return {
    id,
    code: 'GB-2611-0007',
    title: titles[key][lang],
    image: {
      url: `https://cdn.example.sa/products/${key}.jpg`,
      alt: titles[key][lang],
      product_variant_id: null,
    },
    status: 'active',
    starts_at: iso(Date.now() - 2 * 24 * HOUR),
    deadline_at: iso(Date.now() + 2 * 24 * HOUR + 5 * HOUR),
    current_unit_price: sar(6900),
    lowest_unit_price: sar(6000),
    progress: progress({ campaign_id: id }),
    ...overrides,
  };
}

export function campaignDetail(
  lang: Lang = 'ar',
  overrides: Partial<CampaignDetail> = {},
): CampaignDetail {
  const summary = campaignSummary('kettle', lang);
  return {
    ...summary,
    description:
      lang === 'ar'
        ? 'غلاية من الفولاذ المقاوم للصدأ بسعة 1.7 لتر، تغلي الماء في أقل من 4 دقائق، مع إيقاف تلقائي وقاعدة دوّارة 360 درجة. ضمان سنة.'
        : 'Stainless steel kettle, 1.7 L. Boils in under 4 minutes, switches off automatically and sits on a 360° base. One-year warranty.',
    category: {
      id: ids.category,
      slug: 'home-kitchen',
      name: lang === 'ar' ? 'المنزل والمطبخ' : 'Home & kitchen',
      parent_id: null,
    },
    variants: [
      {
        id: ids.kettleBlack,
        sku: 'KET-17-BLK',
        name: lang === 'ar' ? 'أسود' : 'Black',
        option_values: { color: 'black' },
      },
      {
        id: ids.kettleWhite,
        sku: 'KET-17-WHT',
        name: lang === 'ar' ? 'أبيض' : 'White',
        option_values: { color: 'white' },
      },
    ],
    images: [summary.image!],
    tiers: [
      { min_qty: 1, max_qty: 49, unit_price: sar(7900), is_current: false },
      { min_qty: 50, max_qty: 99, unit_price: sar(6900), is_current: true },
      { min_qty: 100, max_qty: 150, unit_price: sar(6000), is_current: false },
    ],
    per_customer_max_qty: 5,
    original_deadline_at: null,
    final_unit_price: null,
    cancellation_rule: 'before_deal_confirmed',
    ...overrides,
  };
}

export function categories(lang: Lang = 'ar'): Category[] {
  return [
    {
      id: ids.category,
      slug: 'home-kitchen',
      name: lang === 'ar' ? 'المنزل والمطبخ' : 'Home & kitchen',
      parent_id: null,
    },
    {
      id: 'f1a2b3c4-d5e6-4f70-8a9b-0c1d2e000002',
      slug: 'electronics',
      name: lang === 'ar' ? 'إلكترونيات' : 'Electronics',
      parent_id: null,
    },
    {
      id: 'f1a2b3c4-d5e6-4f70-8a9b-0c1d2e000003',
      slug: 'office',
      name: lang === 'ar' ? 'المكتب' : 'Office',
      parent_id: null,
    },
  ];
}

export function campaignList(lang: Lang = 'ar'): CampaignSummary[] {
  return [
    campaignSummary('kettle', lang),
    campaignSummary('blender', lang, {
      code: 'GB-2611-0008',
      current_unit_price: sar(8900),
      lowest_unit_price: sar(8900),
      progress: progress({
        campaign_id: ids.blender,
        committed_qty: 64,
        min_target_qty: 60,
        max_qty: 80,
        remaining_qty: 16,
        units_to_target: 0,
        deal_confirmed_at: iso(Date.now() - 5 * HOUR),
        current_unit_price: sar(8900),
      }),
    }),
    campaignSummary('lamp', lang, {
      code: 'GB-2611-0009',
      current_unit_price: sar(12900),
      lowest_unit_price: sar(9900),
      deadline_at: iso(Date.now() + 9 * HOUR),
      progress: progress({
        campaign_id: ids.lamp,
        committed_qty: 12,
        min_target_qty: 40,
        max_qty: 60,
        remaining_qty: 48,
        units_to_target: 28,
        current_unit_price: sar(12900),
      }),
    }),
  ];
}

export function user(overrides: Partial<User> = {}): User {
  return {
    id: ids.user,
    phone: '+966512345678',
    name: 'نورة',
    email: null,
    locale: 'ar',
    privacy_consent: { policy_version: '2026-11', accepted_at: iso(Date.now() - 24 * HOUR) },
    current_privacy_policy_version: '2026-11',
    created_at: iso(Date.now() - 30 * 24 * HOUR),
    ...overrides,
  };
}

export function address(overrides: Partial<Address> = {}): Address {
  return {
    id: ids.address,
    recipient_name: 'نورة العتيبي',
    recipient_phone: '+966512345678',
    city: 'الرياض',
    district: 'العليا',
    street: 'شارع العليا العام',
    building_no: '2401',
    postal_code: '12211',
    additional_no: '3344',
    short_address: 'RRRD2929',
    lat: null,
    lng: null,
    is_default: true,
    ...overrides,
  };
}

export function payment(overrides: Partial<Payment> = {}): Payment {
  return {
    id: ids.payment,
    status: 'pending_authorization',
    amount: sar(13800),
    captured_amount: sar(0),
    refunded_amount: sar(0),
    scheme: null,
    card_last4: null,
    expires_at: iso(Date.now() + 14 * 24 * HOUR),
    rejection_reason: null,
    checkout: {
      publishable_api_key: 'pk_test_fake',
      amount: 13800,
      currency: 'SAR',
      description: 'GB-2611-0007 x2',
      callback_url: 'https://example.sa/payments/return',
      methods: ['creditcard', 'applepay'],
      supported_networks: ['mada', 'visa', 'mastercard'],
      manual: true,
      metadata: { payment_id: ids.payment },
    },
    ...overrides,
  };
}

export function participation(
  lang: Lang = 'ar',
  overrides: Partial<Participation> = {},
): Participation {
  return {
    id: ids.participation,
    campaign: campaignSummary('kettle', lang),
    product_variant: campaignDetail(lang).variants[0],
    quantity: 2,
    status: 'pending_payment',
    hold_unit_price: sar(6900),
    hold_amount: sar(13800),
    final_unit_price: null,
    capture_amount: null,
    reservation_expires_at: iso(Date.now() + 14 * 60_000),
    can_cancel: false,
    payment: payment(),
    order_id: null,
    created_at: iso(Date.now() - 60_000),
    ...overrides,
  };
}

export function authorizedParticipation(lang: Lang = 'ar'): Participation {
  return participation(lang, {
    status: 'authorized',
    can_cancel: true,
    payment: payment({ status: 'authorized', scheme: 'mada', card_last4: '0000', checkout: null }),
  });
}

export function order(lang: Lang = 'ar', overrides: Partial<Order> = {}): Order {
  const shipped = iso(Date.now() - 2 * 24 * HOUR);
  return {
    id: ids.order,
    order_number: 'ORD-2611-000123',
    campaign: campaignSummary('blender', lang, { status: 'fulfilling' }),
    product_variant: {
      id: ids.kettleBlack,
      sku: 'BLD-01',
      name: lang === 'ar' ? 'وردي' : 'Pink',
      option_values: { color: 'pink' },
    },
    quantity: 2,
    unit_price: sar(8900),
    goods_total: sar(17800),
    delivery_fee: sar(0),
    total: sar(17800),
    vat: sar(2322),
    status: 'shipped',
    shipping_address: address(),
    payment: { status: 'captured', captured_amount: sar(17800), refunded_amount: sar(0) },
    shipment: {
      id: 'aa000000-0000-4000-8000-000000000001',
      courier: 'SMSA',
      tracking_number: '290012345678',
      tracking_url: 'https://www.smsaexpress.com/trackingdetails?tracknumbers=290012345678',
      status: 'in_transit',
      shipped_at: shipped,
      delivered_at: null,
      events: [
        {
          status: 'picked_up',
          description: null,
          location: lang === 'ar' ? 'مستودع الرياض' : 'Riyadh warehouse',
          occurred_at: shipped,
        },
        {
          status: 'in_transit',
          description: null,
          location: lang === 'ar' ? 'الرياض' : 'Riyadh',
          occurred_at: iso(Date.now() - 20 * HOUR),
        },
      ],
    },
    invoices: [
      {
        id: 'bb000000-0000-4000-8000-000000000001',
        type: 'simplified_tax_invoice',
        invoice_number: 'INV-2026-000123',
        issued_at: iso(Date.now() - 4 * 24 * HOUR),
        total: sar(17800),
        pdf_url: 'https://example.sa/invoices/INV-2026-000123.pdf',
      },
    ],
    delivered_at: null,
    return_window_ends_at: null,
    created_at: iso(Date.now() - 4 * 24 * HOUR),
    ...overrides,
  };
}

export function page<T>(data: T[], nextCursor: string | null = null) {
  return { data, meta: { next_cursor: nextCursor, per_page: 20 } };
}
