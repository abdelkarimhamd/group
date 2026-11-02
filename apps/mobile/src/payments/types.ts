import type { MoyasarCheckout } from '@/api/types';

export type PaymentSheetResult =
  /** The provider finished; the API decides what happened via POST /payments/{id}/sync. */
  | { kind: 'completed'; providerPaymentId: string }
  /** The customer closed the form without paying. */
  | { kind: 'dismissed' };

export type PaymentSheetProps = {
  visible: boolean;
  paymentId: string;
  checkout: MoyasarCheckout;
  onResult: (result: PaymentSheetResult) => void;
};
