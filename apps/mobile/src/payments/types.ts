import type { MoyasarCheckout } from '@/api/types';

export type PaymentSheetResult =
  /** The provider finished; the API decides what happened via POST /payments/{id}/sync. */
  | { kind: 'completed'; providerPaymentId: string }
  /**
   * The customer closed the form. `providerPaymentId` is set when the provider had already
   * created a payment (for example, the customer left during 3-D Secure): the app records it
   * quietly, so a hold that did go through is still found, and the form can be opened again.
   */
  | { kind: 'dismissed'; providerPaymentId?: string };

export type PaymentSheetProps = {
  visible: boolean;
  paymentId: string;
  checkout: MoyasarCheckout;
  onResult: (result: PaymentSheetResult) => void;
};
