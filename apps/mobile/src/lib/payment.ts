import type { Participation } from '@/api/types';

import { remainingUntil } from './time';

/** How often, and how many times, the payment screen looks again while the bank is processing. */
export const PENDING_CHECK_MS = 3_000;
export const PENDING_CHECKS = 10;

export type PayView =
  'confirming' | 'ready' | 'success' | 'declined' | 'pending' | 'failed' | 'expired' | 'other';

/**
 * What the payment screen shows for a participation. The participation status leads; the
 * latest payment attempt decides between "open the form", "declined, try another card" and
 * "the bank is still processing".
 */
export function payView(
  participation: Participation,
  options: { now: number; syncing: boolean; returnedFromProvider: boolean },
): PayView {
  if (options.syncing) return 'confirming';
  switch (participation.status) {
    case 'authorized':
    case 'captured':
      return 'success';
    case 'payment_failed':
      return 'failed';
    case 'reservation_expired':
      return 'expired';
    case 'pending_payment': {
      if (remainingUntil(participation.reservation_expires_at, options.now).totalMs === 0) {
        return 'expired';
      }
      const payment = participation.payment;
      // A hold the API refused (wrong amount, charged at once…) is voided shortly after; the
      // customer can already try another card.
      if (
        !payment ||
        payment.status === 'failed' ||
        payment.status === 'voided' ||
        payment.rejection_reason
      ) {
        return 'declined';
      }
      if (payment.status === 'pending_authorization') {
        return options.returnedFromProvider || !payment.checkout ? 'pending' : 'ready';
      }
      return 'pending';
    }
    default:
      return 'other';
  }
}
