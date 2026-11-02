import type { CampaignProgress, PriceTier } from "@/lib/api/types";
import { multiplyMoney, type MoneyLike } from "@/i18n/format";

// Display helpers only. The server computes every amount that is held or
// charged; these mirror the spec's rules so the customer sees the same
// numbers before they confirm (Pricing rules: price at join and at close).

/** The tier the next unit sold falls in: the one containing committed + 1. */
export function currentTierIndex(tiers: PriceTier[], committedQty: number): number {
  const next = committedQty + 1;
  const index = tiers.findIndex((tier) => next >= tier.min_qty && next <= tier.max_qty);
  if (index >= 0) return index;
  return next > (tiers.at(-1)?.max_qty ?? 0) ? tiers.length - 1 : 0;
}

/** A tier is reached once committed units fall inside it or beyond it. */
export function isTierReached(tier: PriceTier, committedQty: number): boolean {
  return committedQty >= tier.min_qty;
}

/** Estimated hold: the current unit price times the quantity (fee-free until Q12). */
export function estimateHold(currentUnitPrice: MoneyLike, quantity: number): MoneyLike {
  return multiplyMoney(currentUnitPrice, quantity);
}

/** Percentages for the progress bar: committed units, and where the target sits. */
export function progressPercents(progress: Pick<CampaignProgress, "committed_qty" | "min_target_qty" | "max_qty">) {
  const max = Math.max(progress.max_qty, 1);
  const clamp = (value: number) => Math.min(100, Math.max(0, value));
  return {
    committed: clamp((progress.committed_qty / max) * 100),
    target: clamp((progress.min_target_qty / max) * 100),
  };
}

/** The most units one join may ask for, given stock and the per-customer cap. */
export function maxJoinQuantity(
  remainingQty: number,
  perCustomerMaxQty: number | null | undefined,
  hardCap = 99,
): number {
  const caps = [remainingQty, hardCap];
  if (perCustomerMaxQty && perCustomerMaxQty > 0) caps.push(perCustomerMaxQty);
  return Math.max(0, Math.min(...caps));
}

export const SUCCESS_STATUSES = ["purchasing", "fulfilling", "completed"] as const;

export function isSuccessStatus(status: string): boolean {
  return (SUCCESS_STATUSES as readonly string[]).includes(status);
}
