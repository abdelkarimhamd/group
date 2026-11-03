import type {
  CampaignDetail,
  CampaignProgress,
  CampaignStatus,
  CampaignSummary,
  PriceTier,
} from '@/api/types';

import { isPast } from './time';

/** How the customer app groups the API's campaign statuses (spec: show the last three as success). */
export type CampaignPhase = 'upcoming' | 'open' | 'closing' | 'success' | 'failed' | 'cancelled';

export function campaignPhase(status: CampaignStatus): CampaignPhase {
  switch (status) {
    case 'scheduled':
      return 'upcoming';
    case 'active':
      return 'open';
    case 'closing':
      return 'closing';
    case 'purchasing':
    case 'fulfilling':
    case 'completed':
      return 'success';
    case 'failed':
      return 'failed';
    case 'cancelled':
      return 'cancelled';
  }
}

export function isDealConfirmed(progress: CampaignProgress): boolean {
  return progress.deal_confirmed_at !== null || progress.units_to_target === 0;
}

/**
 * Fill fractions for the progress bar, which runs from 0 to the maximum quantity with a mark
 * at the minimum target. Both are clamped to 0..1.
 */
export function progressFractions(progress: CampaignProgress): {
  committed: number;
  target: number;
} {
  const max = Math.max(progress.max_qty, 1);
  const clamp = (value: number) => Math.min(1, Math.max(0, value));
  return {
    committed: clamp(progress.committed_qty / max),
    target: clamp(progress.min_target_qty / max),
  };
}

export function isJoinable(campaign: CampaignSummary, now: number = Date.now()): boolean {
  return (
    campaign.status === 'active' &&
    campaign.progress.remaining_qty > 0 &&
    !isPast(campaign.deadline_at, now)
  );
}

/** Upper bound for the quantity stepper; the API still enforces the per-customer total. */
export function maxSelectableQuantity(campaign: CampaignDetail): number {
  const remaining = Math.max(0, campaign.progress.remaining_qty);
  return campaign.per_customer_max_qty === null
    ? remaining
    : Math.min(remaining, campaign.per_customer_max_qty);
}

/** Tiers whose first unit is already committed. */
export function isTierReached(tier: PriceTier, committedQty: number): boolean {
  return committedQty >= tier.min_qty;
}

/**
 * The progress object to show: the higher `version` of the two (PostgreSQL bumps it on every
 * change to the campaign; `updated_at` has whole seconds only). The same version is the same
 * state, so a tie takes `live`.
 */
export function latestProgress(
  campaignProgress: CampaignProgress,
  live: CampaignProgress | undefined,
): CampaignProgress {
  if (!live) return campaignProgress;
  return live.version >= campaignProgress.version ? live : campaignProgress;
}
