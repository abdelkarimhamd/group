import type { OrderStatus } from '@/api/types';

/** The happy path an order walks, for the step indicator. */
export const ORDER_STEPS = ['confirmed', 'processing', 'shipped', 'delivered'] as const;
export type OrderStep = (typeof ORDER_STEPS)[number];

/**
 * Index of the last reached step, or null when the order left the happy path (cancelled or
 * returned), where the status pill alone says more than a half-filled tracker.
 */
export function orderStepIndex(status: OrderStatus): number | null {
  switch (status) {
    case 'confirmed':
      return 0;
    case 'processing':
    case 'ready_to_ship':
      return 1;
    case 'shipped':
      return 2;
    case 'delivered':
    case 'completed':
      return 3;
    default:
      return null;
  }
}
