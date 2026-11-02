import type { Participation } from '@/api/types';

import { remainingUntil } from './time';

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
      if (!payment || payment.status === 'failed' || payment.status === 'voided') {
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
