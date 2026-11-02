/**
 * Client-side check of a tier set, mirroring the rules the API enforces on
 * PUT /admin/campaigns/{id}/tiers: tiers cover 1 to max_qty with no gaps or
 * overlaps, and the unit price never rises with quantity. The API is the
 * judge; this only explains problems before the admin presses Save.
 */
export type TierDraft = { min_qty: number; max_qty: number; unit_price_halalas: number };

export type TierIssueCode =
  | 'tiers_missing'
  | 'tiers_do_not_start_at_one'
  | 'tier_range_invalid'
  | 'tiers_gap'
  | 'tiers_overlap'
  | 'tiers_do_not_reach_max'
  | 'tiers_exceed_max'
  | 'price_rises_with_quantity';

export type TierIssue = { code: TierIssueCode; index: number };

export function checkTiers(tiers: readonly TierDraft[], maxQty: number): TierIssue[] {
  if (tiers.length === 0) return [{ code: 'tiers_missing', index: 0 }];
  const sorted = tiers
    .map((tier, index) => ({ tier, index }))
    .sort((a, b) => a.tier.min_qty - b.tier.min_qty);
  const issues: TierIssue[] = [];

  const first = sorted[0]!;
  if (first.tier.min_qty !== 1)
    issues.push({ code: 'tiers_do_not_start_at_one', index: first.index });

  sorted.forEach(({ tier, index }, i) => {
    if (tier.max_qty < tier.min_qty) issues.push({ code: 'tier_range_invalid', index });
    const next = sorted[i + 1];
    if (!next) return;
    if (next.tier.min_qty > tier.max_qty + 1) issues.push({ code: 'tiers_gap', index: next.index });
    if (next.tier.min_qty <= tier.max_qty)
      issues.push({ code: 'tiers_overlap', index: next.index });
    if (next.tier.unit_price_halalas > tier.unit_price_halalas) {
      issues.push({ code: 'price_rises_with_quantity', index: next.index });
    }
  });

  const last = sorted[sorted.length - 1]!;
  if (last.tier.max_qty < maxQty)
    issues.push({ code: 'tiers_do_not_reach_max', index: last.index });
  if (last.tier.max_qty > maxQty) issues.push({ code: 'tiers_exceed_max', index: last.index });

  return issues;
}

/**
 * Lays rows end to end: the first starts at 1, each next one starts right
 * after the previous maximum. Editing only the maximums then cannot leave a
 * gap or an overlap.
 */
export function chainTiers<T extends { max_qty: number | null }>(
  rows: readonly T[],
): (T & { min_qty: number })[] {
  let nextMin = 1;
  return rows.map((row) => {
    const out = { ...row, min_qty: nextMin };
    nextMin = (row.max_qty ?? nextMin) + 1;
    return out;
  });
}

/** The tier the given unit falls in (for example committed + 1 for the next hold). */
export function tierFor<T extends { min_qty: number; max_qty: number }>(
  tiers: readonly T[],
  unit: number,
): T | undefined {
  return tiers.find((tier) => unit >= tier.min_qty && unit <= tier.max_qty);
}
