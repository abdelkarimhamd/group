import { describe, expect, it } from "vitest";
import { currentTierIndex, estimateHold, isSuccessStatus, maxJoinQuantity, progressPercents } from "@/lib/pricing";
import { sar } from "@/i18n/format";
import type { PriceTier } from "@/lib/api/types";

const tier = (min: number, max: number, price: number) =>
  ({ min_qty: min, max_qty: max, unit_price: { ...sar(price), formatted: "" } }) as unknown as PriceTier;
const tiers = [tier(1, 49, 8900), tier(50, 99, 7900), tier(100, 150, 6900)];

describe("pricing display", () => {
  it("finds the tier the next unit falls in", () => {
    expect(currentTierIndex(tiers, 0)).toBe(0);
    expect(currentTierIndex(tiers, 48)).toBe(0);
    expect(currentTierIndex(tiers, 49)).toBe(1);
    expect(currentTierIndex(tiers, 99)).toBe(2);
    expect(currentTierIndex(tiers, 150)).toBe(2);
  });

  it("estimates the hold in halalas", () => {
    expect(estimateHold(sar(7900), 3)).toEqual(sar(23700));
  });

  it("caps the quantity by stock, the per-customer limit and 99", () => {
    expect(maxJoinQuantity(10, null)).toBe(10);
    expect(maxJoinQuantity(10, 3)).toBe(3);
    expect(maxJoinQuantity(500, null)).toBe(99);
    expect(maxJoinQuantity(0, 3)).toBe(0);
  });

  it("places the target on the progress bar", () => {
    expect(progressPercents({ committed_qty: 50, min_target_qty: 100, max_qty: 200 })).toEqual({ committed: 25, target: 50 });
    expect(progressPercents({ committed_qty: 300, min_target_qty: 100, max_qty: 200 }).committed).toBe(100);
  });

  it("knows which campaign statuses mean the deal happened", () => {
    expect(isSuccessStatus("completed")).toBe(true);
    expect(isSuccessStatus("failed")).toBe(false);
  });
});
