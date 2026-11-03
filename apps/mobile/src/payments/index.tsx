import { config } from '@/config';

import { FakePaymentSheet } from './FakePaymentSheet';
import { MoyasarPaymentSheet } from './MoyasarPaymentSheet';
import type { PaymentSheetProps } from './types';

export type { PaymentSheetResult } from './types';

/**
 * The card form for a hold, picked by `config.paymentProvider`: Moyasar's form (on the website,
 * inside a WebView), or the fake that hands back the API's fake provider IDs.
 */
export function PaymentSheet(props: PaymentSheetProps) {
  return config.paymentProvider === 'moyasar' ? (
    <MoyasarPaymentSheet {...props} />
  ) : (
    <FakePaymentSheet {...props} />
  );
}
