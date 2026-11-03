// Seed data for the local fixture API. Times are relative to server start so
// the demo always has open, upcoming and finished campaigns.

export type Text = { ar: string; en: string };

export type FixtureCategory = { id: string; slug: string; name: Text; parent_id: string | null };

export type FixtureVariant = { id: string; sku: string; name: Text; option_values: Record<string, string> };

export type FixtureTier = { min_qty: number; max_qty: number; unit_price_halalas: number };

export type FixtureCampaign = {
  id: string;
  code: string;
  slug: string;
  title: Text;
  description: Text;
  category_id: string;
  status: "scheduled" | "active" | "closing" | "purchasing" | "fulfilling" | "completed" | "failed" | "cancelled";
  starts_at: number;
  deadline_at: number;
  original_deadline_at: number | null;
  min_target_qty: number;
  max_qty: number;
  committed_qty: number;
  per_customer_max_qty: number | null;
  tiers: FixtureTier[];
  variants: FixtureVariant[];
  palette: [string, string];
  final_unit_price_halalas: number | null;
  deal_confirmed_at: number | null;
  updated_at: number;
  /** CampaignProgress.version: bumped with every change, as the API's trigger does. */
  version: number;
};

const HOUR = 3600_000;
const DAY = 24 * HOUR;

export const categories: FixtureCategory[] = [
  { id: "6f1c2a3e-0b7d-4c1e-9a10-2b3c4d5e6f01", slug: "home-kitchen", name: { ar: "المنزل والمطبخ", en: "Home & kitchen" }, parent_id: null },
  { id: "6f1c2a3e-0b7d-4c1e-9a10-2b3c4d5e6f02", slug: "electronics", name: { ar: "إلكترونيات", en: "Electronics" }, parent_id: null },
  { id: "6f1c2a3e-0b7d-4c1e-9a10-2b3c4d5e6f03", slug: "outdoor", name: { ar: "رحلات وتخييم", en: "Outdoor & camping" }, parent_id: null },
];

function variant(id: string, sku: string, ar: string, en: string, color: string): FixtureVariant {
  return { id, sku, name: { ar, en }, option_values: { color } };
}

export function seedCampaigns(now: number): FixtureCampaign[] {
  return [
    {
      id: "0b6f8f2e-6a43-4f7e-9d0e-1a2b3c4d5e01",
      code: "GB-2611-0007",
      slug: "kettle",
      title: { ar: "غلاية كهربائية ستانلس 1.7 لتر", en: "Stainless electric kettle, 1.7 L" },
      description: {
        ar: "غلاية من الستانلس ستيل بقدرة 2200 واط، مع فصل تلقائي وحماية من التشغيل دون ماء.\nالضمان: سنة واحدة.",
        en: "A 2200 W stainless steel kettle with automatic shut-off and boil-dry protection.\nWarranty: one year.",
      },
      category_id: categories[0].id,
      status: "active",
      starts_at: now - 2 * DAY,
      deadline_at: now + 3 * DAY + 5 * HOUR,
      original_deadline_at: null,
      min_target_qty: 100,
      max_qty: 150,
      committed_qty: 53,
      per_customer_max_qty: null,
      tiers: [
        { min_qty: 1, max_qty: 49, unit_price_halalas: 8900 },
        { min_qty: 50, max_qty: 99, unit_price_halalas: 7900 },
        { min_qty: 100, max_qty: 150, unit_price_halalas: 6900 },
      ],
      variants: [
        variant("0b6f8f2e-6a43-4f7e-9d0e-1a2b3c4d5f01", "KET-17-BLK", "أسود", "Black", "black"),
        variant("0b6f8f2e-6a43-4f7e-9d0e-1a2b3c4d5f02", "KET-17-WHT", "أبيض", "White", "white"),
      ],
      palette: ["#0f6b5d", "#93e9da"],
      final_unit_price_halalas: null,
      deal_confirmed_at: null,
      updated_at: now,
      version: 1,
    },
    {
      id: "0b6f8f2e-6a43-4f7e-9d0e-1a2b3c4d5e02",
      code: "GB-2611-0009",
      slug: "earbuds",
      title: { ar: "سماعات لاسلكية بعزل الضوضاء", en: "Noise-cancelling wireless earbuds" },
      description: {
        ar: "عزل نشط للضوضاء، وبطارية تكفي 30 ساعة مع العلبة، ومقاومة للماء بمعيار IPX5.",
        en: "Active noise cancelling, 30 hours of battery with the case, and IPX5 water resistance.",
      },
      category_id: categories[1].id,
      status: "active",
      starts_at: now - 4 * DAY,
      deadline_at: now + 20 * HOUR,
      original_deadline_at: null,
      min_target_qty: 100,
      max_qty: 200,
      committed_qty: 112,
      per_customer_max_qty: 3,
      tiers: [
        { min_qty: 1, max_qty: 99, unit_price_halalas: 14900 },
        { min_qty: 100, max_qty: 149, unit_price_halalas: 12900 },
        { min_qty: 150, max_qty: 200, unit_price_halalas: 11900 },
      ],
      variants: [variant("0b6f8f2e-6a43-4f7e-9d0e-1a2b3c4d5f03", "EAR-ANC-BLK", "أسود", "Black", "black")],
      palette: ["#1e293b", "#64748b"],
      final_unit_price_halalas: null,
      deal_confirmed_at: now - 6 * HOUR,
      updated_at: now,
      version: 1,
    },
    {
      id: "0b6f8f2e-6a43-4f7e-9d0e-1a2b3c4d5e03",
      code: "GB-2611-0011",
      slug: "chair",
      title: { ar: "كرسي تخييم قابل للطي", en: "Folding camping chair" },
      description: {
        ar: "هيكل ألمنيوم خفيف يتحمل حتى 120 كجم، مع حقيبة حمل وجيب جانبي للأكواب.",
        en: "A light aluminium frame that holds up to 120 kg, with a carry bag and a side cup holder.",
      },
      category_id: categories[2].id,
      status: "active",
      starts_at: now - DAY,
      deadline_at: now + 6 * DAY,
      original_deadline_at: null,
      min_target_qty: 60,
      max_qty: 80,
      committed_qty: 8,
      per_customer_max_qty: 4,
      tiers: [
        { min_qty: 1, max_qty: 29, unit_price_halalas: 11900 },
        { min_qty: 30, max_qty: 59, unit_price_halalas: 9900 },
        { min_qty: 60, max_qty: 80, unit_price_halalas: 8900 },
      ],
      variants: [
        variant("0b6f8f2e-6a43-4f7e-9d0e-1a2b3c4d5f04", "CHR-FLD-GRN", "أخضر زيتي", "Olive", "olive"),
        variant("0b6f8f2e-6a43-4f7e-9d0e-1a2b3c4d5f05", "CHR-FLD-SND", "رملي", "Sand", "sand"),
      ],
      palette: ["#4d7c0f", "#d9f99d"],
      final_unit_price_halalas: null,
      deal_confirmed_at: null,
      updated_at: now,
      version: 1,
    },
    {
      id: "0b6f8f2e-6a43-4f7e-9d0e-1a2b3c4d5e04",
      code: "GB-2611-0012",
      slug: "fryer",
      title: { ar: "قلاية هوائية رقمية 5.5 لتر", en: "Digital air fryer, 5.5 L" },
      description: {
        ar: "ثمانية برامج جاهزة وسلة غير لاصقة قابلة للغسل في غسالة الصحون.",
        en: "Eight presets and a non-stick basket that is dishwasher safe.",
      },
      category_id: categories[0].id,
      status: "active",
      starts_at: now - 5 * DAY,
      deadline_at: now + 2 * DAY,
      original_deadline_at: now + DAY,
      min_target_qty: 120,
      max_qty: 150,
      committed_qty: 146,
      per_customer_max_qty: null,
      tiers: [
        { min_qty: 1, max_qty: 59, unit_price_halalas: 29900 },
        { min_qty: 60, max_qty: 119, unit_price_halalas: 26900 },
        { min_qty: 120, max_qty: 150, unit_price_halalas: 24900 },
      ],
      variants: [variant("0b6f8f2e-6a43-4f7e-9d0e-1a2b3c4d5f06", "AFR-55-BLK", "أسود", "Black", "black")],
      palette: ["#b45309", "#fde68a"],
      final_unit_price_halalas: null,
      deal_confirmed_at: now - 2 * DAY,
      updated_at: now,
      version: 1,
    },
    {
      id: "0b6f8f2e-6a43-4f7e-9d0e-1a2b3c4d5e05",
      code: "GB-2611-0014",
      slug: "containers",
      title: { ar: "طقم حافظات طعام زجاجية 10 قطع", en: "Glass food containers, set of 10" },
      description: {
        ar: "زجاج بوروسيليكات آمن للفرن والميكروويف، بأغطية محكمة الإغلاق.",
        en: "Oven and microwave safe borosilicate glass with leak-proof lids.",
      },
      category_id: categories[0].id,
      status: "scheduled",
      starts_at: now + 2 * DAY,
      deadline_at: now + 9 * DAY,
      original_deadline_at: null,
      min_target_qty: 80,
      max_qty: 120,
      committed_qty: 0,
      per_customer_max_qty: null,
      tiers: [
        { min_qty: 1, max_qty: 39, unit_price_halalas: 13900 },
        { min_qty: 40, max_qty: 79, unit_price_halalas: 11900 },
        { min_qty: 80, max_qty: 120, unit_price_halalas: 9900 },
      ],
      variants: [variant("0b6f8f2e-6a43-4f7e-9d0e-1a2b3c4d5f07", "GLS-10-CLR", "شفاف", "Clear", "clear")],
      palette: ["#0369a1", "#bae6fd"],
      final_unit_price_halalas: null,
      deal_confirmed_at: null,
      updated_at: now,
      version: 1,
    },
    {
      id: "0b6f8f2e-6a43-4f7e-9d0e-1a2b3c4d5e06",
      code: "GB-2610-0003",
      slug: "lamp",
      title: { ar: "مصباح مكتب LED بشاحن لاسلكي", en: "LED desk lamp with wireless charger" },
      description: {
        ar: "خمس درجات للإضاءة وشاحن لاسلكي مدمج بقوة 15 واط.",
        en: "Five brightness levels and a built-in 15 W wireless charger.",
      },
      category_id: categories[1].id,
      status: "completed",
      starts_at: now - 30 * DAY,
      deadline_at: now - 23 * DAY,
      original_deadline_at: null,
      min_target_qty: 50,
      max_qty: 100,
      committed_qty: 84,
      per_customer_max_qty: null,
      tiers: [
        { min_qty: 1, max_qty: 49, unit_price_halalas: 7900 },
        { min_qty: 50, max_qty: 79, unit_price_halalas: 6900 },
        { min_qty: 80, max_qty: 100, unit_price_halalas: 5900 },
      ],
      variants: [variant("0b6f8f2e-6a43-4f7e-9d0e-1a2b3c4d5f08", "LMP-LED-WHT", "أبيض", "White", "white")],
      palette: ["#6d28d9", "#ddd6fe"],
      final_unit_price_halalas: 5900,
      deal_confirmed_at: now - 25 * DAY,
      updated_at: now - 23 * DAY,
      version: 1,
    },
    {
      id: "0b6f8f2e-6a43-4f7e-9d0e-1a2b3c4d5e07",
      code: "GB-2610-0005",
      slug: "fan",
      title: { ar: "مروحة محمولة قابلة للشحن", en: "Rechargeable portable fan" },
      description: {
        ar: "ثلاث سرعات وبطارية تدوم حتى 12 ساعة.",
        en: "Three speeds and a battery that lasts up to 12 hours.",
      },
      category_id: categories[2].id,
      status: "failed",
      starts_at: now - 15 * DAY,
      deadline_at: now - 8 * DAY,
      original_deadline_at: null,
      min_target_qty: 150,
      max_qty: 200,
      committed_qty: 61,
      per_customer_max_qty: null,
      tiers: [
        { min_qty: 1, max_qty: 99, unit_price_halalas: 5900 },
        { min_qty: 100, max_qty: 200, unit_price_halalas: 4900 },
      ],
      variants: [variant("0b6f8f2e-6a43-4f7e-9d0e-1a2b3c4d5f09", "FAN-PRT-WHT", "أبيض", "White", "white")],
      palette: ["#be123c", "#fecdd3"],
      final_unit_price_halalas: null,
      deal_confirmed_at: null,
      updated_at: now - 8 * DAY,
      version: 1,
    },
  ];
}

/** A simple product illustration so the demo has pictures without hotlinking. */
export function productSvg(campaign: FixtureCampaign, index: number): string {
  const [dark, light] = campaign.palette;
  const shapes = [
    `<circle cx="300" cy="300" r="150" fill="${light}" opacity="0.9"/><rect x="210" y="380" width="180" height="40" rx="20" fill="#ffffff" opacity="0.8"/>`,
    `<rect x="170" y="170" width="260" height="260" rx="60" fill="${light}" opacity="0.9"/><circle cx="300" cy="300" r="70" fill="#ffffff" opacity="0.8"/>`,
    `<path d="M300 140 L460 420 L140 420 Z" fill="${light}" opacity="0.9"/><circle cx="300" cy="330" r="50" fill="#ffffff" opacity="0.8"/>`,
  ];
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 600"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${dark}"/><stop offset="1" stop-color="${dark}" stop-opacity="0.75"/></linearGradient></defs><rect width="600" height="600" fill="url(#g)"/>${shapes[index % shapes.length]}<text x="300" y="540" text-anchor="middle" font-family="sans-serif" font-size="32" fill="#ffffff" opacity="0.9">${campaign.title.en}</text></svg>`;
}
