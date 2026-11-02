import { FakePaymentSheet } from './FakePaymentSheet';
import type { PaymentSheetProps } from './types';

export type { PaymentSheetResult } from './types';

/**
 * The card form for a hold. Only the fake exists today; the Moyasar integration (last, with
 * the other third parties) adds a second implementation behind this same component, picked
 * by `config.paymentProvider`.
 */
export function PaymentSheet(props: PaymentSheetProps) {
  return <FakePaymentSheet {...props} />;
}
