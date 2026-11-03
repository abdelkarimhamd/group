import { campaignDetail, campaignSummary, progress } from '@/test/fixtures';

import {
  campaignPhase,
  isDealConfirmed,
  isJoinable,
  isTierReached,
  latestProgress,
  maxSelectableQuantity,
  progressFractions,
} from '../campaign';

describe('campaignPhase', () => {
  it('shows purchasing, fulfilling and completed as success (spec)', () => {
    expect(campaignPhase('purchasing')).toBe('success');
    expect(campaignPhase('fulfilling')).toBe('success');
    expect(campaignPhase('completed')).toBe('success');
    expect(campaignPhase('active')).toBe('open');
    expect(campaignPhase('scheduled')).toBe('upcoming');
  });
});

describe('progress', () => {
  it('confirms the deal from the timestamp or a zero gap', () => {
    expect(isDealConfirmed(progress())).toBe(false);
    expect(isDealConfirmed(progress({ units_to_target: 0 }))).toBe(true);
    expect(isDealConfirmed(progress({ deal_confirmed_at: '2026-11-01T10:00:00Z' }))).toBe(true);
  });

  it('measures the bar against the maximum, with the target as a mark', () => {
    const fractions = progressFractions(
      progress({ committed_qty: 50, min_target_qty: 100, max_qty: 200 }),
    );
    expect(fractions).toEqual({ committed: 0.25, target: 0.5 });
  });

  it('clamps the bar', () => {
    expect(progressFractions(progress({ committed_qty: 300, max_qty: 200 })).committed).toBe(1);
    expect(progressFractions(progress({ max_qty: 0, min_target_qty: 0 })).target).toBe(0);
  });

  it('prefers the progress with the higher version', () => {
    const older = progress({ committed_qty: 10, version: 10 });
    const newer = progress({ committed_qty: 12, version: 11 });
    expect(latestProgress(older, newer)).toBe(newer);
    expect(latestProgress(newer, older)).toBe(newer);
    expect(latestProgress(older, undefined)).toBe(older);
  });

  it('goes by version, not by the whole-second time', () => {
    // Two changes in one second: the time cannot order them, the version can.
    const later = progress({ committed_qty: 60, version: 12, updated_at: '2026-11-02T09:00:05Z' });
    const earlier = progress({
      committed_qty: 58,
      version: 11,
      updated_at: '2026-11-02T09:00:05Z',
    });
    expect(latestProgress(later, earlier)).toBe(later);
  });

  it('takes the live copy of the same version', () => {
    const shown = progress();
    const live = progress();
    expect(latestProgress(shown, live)).toBe(live);
  });
});

describe('isJoinable', () => {
  const now = Date.now();

  it('needs an active campaign with stock before its deadline', () => {
    expect(isJoinable(campaignSummary(), now)).toBe(true);
    expect(isJoinable(campaignSummary('kettle', 'en', { status: 'scheduled' }), now)).toBe(false);
    expect(
      isJoinable(
        campaignSummary('kettle', 'en', { deadline_at: new Date(now - 1).toISOString() }),
        now,
      ),
    ).toBe(false);
    expect(
      isJoinable(
        campaignSummary('kettle', 'en', { progress: progress({ remaining_qty: 0 }) }),
        now,
      ),
    ).toBe(false);
  });
});

describe('maxSelectableQuantity', () => {
  it('is the smaller of stock left and the per-customer cap', () => {
    expect(maxSelectableQuantity(campaignDetail('en'))).toBe(5);
    expect(
      maxSelectableQuantity(campaignDetail('en', { progress: progress({ remaining_qty: 3 }) })),
    ).toBe(3);
    expect(maxSelectableQuantity(campaignDetail('en', { per_customer_max_qty: null }))).toBe(95);
  });
});

describe('isTierReached', () => {
  it('counts a tier as reached from its first unit', () => {
    const tier = campaignDetail('en').tiers[1];
    expect(isTierReached(tier, 49)).toBe(false);
    expect(isTierReached(tier, 50)).toBe(true);
  });
});
