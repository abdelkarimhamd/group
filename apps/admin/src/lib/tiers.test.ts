import { describe, expect, it } from 'vitest';
import { chainTiers, checkTiers, tierFor } from './tiers';

const tier = (min_qty: number, max_qty: number, unit_price_halalas: number) => ({
  min_qty,
  max_qty,
  unit_price_halalas,
});

const codes = (issues: { code: string }[]) => issues.map((i) => i.code);

describe('checkTiers', () => {
  it('accepts tiers that cover 1 to max with falling prices', () => {
    expect(checkTiers([tier(1, 100, 9900), tier(101, 300, 8900)], 300)).toEqual([]);
  });

  it('accepts equal prices across tiers', () => {
    expect(checkTiers([tier(1, 50, 9900), tier(51, 100, 9900)], 100)).toEqual([]);
  });

  it('needs at least one tier', () => {
    expect(codes(checkTiers([], 100))).toEqual(['tiers_missing']);
  });

  it('finds gaps, overlaps and bad ranges', () => {
    expect(codes(checkTiers([tier(2, 100, 9900)], 100))).toEqual(['tiers_do_not_start_at_one']);
    expect(codes(checkTiers([tier(1, 50, 9900), tier(52, 100, 8900)], 100))).toEqual(['tiers_gap']);
    expect(codes(checkTiers([tier(1, 50, 9900), tier(50, 100, 8900)], 100))).toEqual([
      'tiers_overlap',
    ]);
    expect(codes(checkTiers([tier(1, 0, 9900)], 0))).toContain('tier_range_invalid');
  });

  it('checks the last tier against the campaign maximum', () => {
    expect(codes(checkTiers([tier(1, 90, 9900)], 100))).toEqual(['tiers_do_not_reach_max']);
    expect(codes(checkTiers([tier(1, 120, 9900)], 100))).toEqual(['tiers_exceed_max']);
  });

  it('refuses a price that rises with quantity and points at that tier', () => {
    const issues = checkTiers([tier(1, 50, 8900), tier(51, 100, 9900)], 100);
    expect(issues).toEqual([{ code: 'price_rises_with_quantity', index: 1 }]);
  });

  it('reports the original row index even when rows are out of order', () => {
    const issues = checkTiers([tier(51, 100, 9900), tier(1, 50, 8900)], 100);
    expect(issues).toEqual([{ code: 'price_rises_with_quantity', index: 0 }]);
  });
});

describe('chainTiers', () => {
  it('starts at 1 and continues after each maximum', () => {
    const rows = chainTiers([{ max_qty: 100 }, { max_qty: 250 }, { max_qty: 300 }]);
    expect(rows.map((r) => r.min_qty)).toEqual([1, 101, 251]);
  });

  it('copes with a maximum still being typed', () => {
    const rows = chainTiers([{ max_qty: null }, { max_qty: 50 }]);
    expect(rows.map((r) => r.min_qty)).toEqual([1, 2]);
  });
});

describe('tierFor', () => {
  const tiers = [tier(1, 100, 9900), tier(101, 300, 8900)];
  it('finds the tier a unit falls in', () => {
    expect(tierFor(tiers, 1)?.unit_price_halalas).toBe(9900);
    expect(tierFor(tiers, 101)?.unit_price_halalas).toBe(8900);
    expect(tierFor(tiers, 301)).toBeUndefined();
  });
});
